<?php

namespace App\Services\Assistant;

use App\Ai\Agents\MultisuiteAssistant;
use App\Models\AssistantSetting;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\InvalidApiKeyException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Conversations of the portal chat (MCP-02, docs/MCP.md). Every operation is scoped to the
 * person: a conversation id from the browser is checked against its owner before it is read,
 * continued or deleted. Failures are reported as failures, never as an invented answer.
 */
class AssistantChat
{
    public const MAX_MESSAGE = 4000;

    public const RETENTION_DAYS = 30;

    /** @var array<string, string> */
    public const ERRORS = [
        'providers' => 'El asistente no tiene ningún proveedor de IA activo. Avisa a quien administra Multisuite.',
        'tokens' => 'Has llegado al límite diario de uso del asistente. Vuelve a intentarlo mañana.',
        'conversation' => 'Esa conversación no existe o no es tuya.',
        'key' => 'El proveedor de IA rechaza la clave configurada. Avisa a quien administra Multisuite.',
        'limit' => 'El proveedor de IA ha alcanzado su límite de uso. Prueba más tarde.',
        'unavailable' => 'El proveedor de IA no responde ahora mismo. Prueba de nuevo en unos minutos.',
        'failed' => 'No se ha podido obtener respuesta. Prueba de nuevo; si sigue pasando, avisa a quien administra Multisuite.',
        'pending' => 'Antes de seguir, aprueba o rechaza los cambios que ha propuesto el asistente.',
        'nothing' => 'No hay ningún cambio pendiente de aprobar en esta conversación.',
    ];

    public function __construct(private AssistantAccess $access, private AssistantProviders $providers, private ChangeDescriber $describer) {}

    /** @return Collection<int, stdClass> id, title, updated_at */
    public function conversations(User $user): Collection
    {
        return $this->owned($user)->orderByDesc('updated_at')->limit(50)->get(['id', 'title', 'updated_at']);
    }

    /** @return Collection<int, stdClass> role, content */
    public function messages(User $user, string $conversationId): Collection
    {
        $this->assertOwns($user, $conversationId);

        return DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])->where('content', '!=', '')
            ->orderBy('created_at')->orderBy('id')->get(['role', 'content']);
    }

    /**
     * Sends one question and returns the conversation id and the answer. $onDelta receives
     * each piece of the answer as it arrives (already escaped for HTML).
     *
     * @param  Closure(string): void|null  $onDelta
     * @return array{conversation: string, text: string}
     */
    public function send(User $user, ?string $conversationId, string $message, ?Closure $onDelta = null): array
    {
        $chain = $this->ready($user, $conversationId);
        if ($conversationId !== null && $this->pending($user, $conversationId) !== []) {
            throw new RuntimeException(self::ERRORS['pending']);
        }

        return $this->run($user, $conversationId, $message, $chain, $onDelta);
    }

    /**
     * Changes the assistant proposed and is waiting to make, described from the person's own
     * data (names are looked up here, never taken from the model).
     *
     * @return list<array{id: string, tool: string, description: string}>
     */
    public function pending(User $user, string $conversationId): array
    {
        $this->assertOwns($user, $conversationId);
        $store = app(ConversationStore::class);
        if (! $store instanceof ResolvesPendingApprovals) {
            return [];
        }

        return array_values(array_map(fn (PendingApproval $approval): array => [
            'id' => $approval->id,
            'tool' => $approval->tool,
            'description' => $this->describer->describe($user, $approval->tool, $approval->arguments),
        ], $store->pendingApprovalsFor($conversationId)));
    }

    /**
     * Approves or rejects every pending change of a conversation and lets the assistant
     * continue. Only the person who owns the conversation can decide.
     *
     * @param  Closure(string): void|null  $onDelta
     * @return array{conversation: string, text: string}
     */
    public function decide(User $user, string $conversationId, bool $approve, ?Closure $onDelta = null): array
    {
        $chain = $this->ready($user, $conversationId);
        if ($this->pending($user, $conversationId) === []) {
            throw new RuntimeException(self::ERRORS['nothing']);
        }
        $decisions = $approve ? Decision::approveAll() : Decision::rejectAll('La persona no ha aprobado este cambio. No lo intentes de nuevo salvo que te lo pida.');

        return $this->run($user, $conversationId, $decisions, $chain, $onDelta);
    }

    /**
     * Access, ownership, budget and providers, in that order.
     *
     * @return array<string, string>
     */
    private function ready(User $user, ?string $conversationId): array
    {
        $denial = $this->access->denial($user);
        if ($denial !== null) {
            throw new RuntimeException(AssistantAccess::DENIALS[$denial]);
        }
        if ($conversationId !== null) {
            $this->assertOwns($user, $conversationId);
        }
        if ($this->tokensUsedToday($user) >= AssistantSetting::current()->daily_tokens) {
            throw new RuntimeException(self::ERRORS['tokens']);
        }
        $chain = $this->providers->chain();
        if ($chain === []) {
            throw new RuntimeException(self::ERRORS['providers']);
        }

        return $chain;
    }

    /**
     * @param  array<string, string>  $chain
     * @param  Closure(string): void|null  $onDelta
     * @return array{conversation: string, text: string}
     */
    private function run(User $user, ?string $conversationId, string|Decisions $prompt, array $chain, ?Closure $onDelta): array
    {
        $agent = (new MultisuiteAssistant($user))->continueOrStart($conversationId, as: $user);
        try {
            $response = $agent->stream($prompt, provider: $chain, timeout: 90);
            foreach ($response as $event) {
                if ($event instanceof TextDelta && $onDelta !== null) {
                    $onDelta(e($event->delta));
                }
            }
        } catch (InvalidApiKeyException) {
            throw new RuntimeException(self::ERRORS['key']);
        } catch (RateLimitedException|InsufficientCreditsException) {
            throw new RuntimeException(self::ERRORS['limit']);
        } catch (ProviderOverloadedException|ProviderConnectionException) {
            throw new RuntimeException(self::ERRORS['unavailable']);
        } catch (Throwable $exception) {
            // Only the type: provider messages may echo the request or the remote answer.
            Log::warning('Assistant answer failed.', ['exception' => $exception::class]);
            throw new RuntimeException(self::ERRORS['failed']);
        }
        $this->recordUsage($user, $response->usage->inputTokens ?? 0, $response->usage->outputTokens ?? 0);

        return ['conversation' => (string) ($response->conversationId ?? $conversationId), 'text' => (string) $response->text];
    }

    public function delete(User $user, string $conversationId): void
    {
        $this->assertOwns($user, $conversationId);
        DB::transaction(function () use ($conversationId): void {
            DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->delete();
            DB::table('agent_conversations')->where('id', $conversationId)->delete();
        });
    }

    /** Deletes conversations untouched for RETENTION_DAYS (decision D4). Returns how many. */
    public function prune(): int
    {
        $ids = DB::table('agent_conversations')->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS))->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                DB::table('agent_conversation_messages')->whereIn('conversation_id', $chunk)->delete();
                DB::table('agent_conversations')->whereIn('id', $chunk)->delete();
            });
        }

        return $ids->count();
    }

    public function tokensUsedToday(User $user): int
    {
        $row = DB::table('assistant_usage')->where('user_id', $user->id)->where('day', now()->toDateString())->first(['input_tokens', 'output_tokens']);

        return $row === null ? 0 : (int) $row->input_tokens + (int) $row->output_tokens;
    }

    private function recordUsage(User $user, int $input, int $output): void
    {
        $key = ['user_id' => $user->id, 'day' => now()->toDateString()];
        // Insert-or-ignore then increment: safe when two answers finish at the same time.
        DB::table('assistant_usage')->insertOrIgnore($key + ['prompts' => 0, 'input_tokens' => 0, 'output_tokens' => 0]);
        DB::table('assistant_usage')->where($key)->incrementEach(['prompts' => 1, 'input_tokens' => max(0, $input), 'output_tokens' => max(0, $output)]);
    }

    private function owned(User $user): Builder
    {
        return DB::table('agent_conversations')->where('participant_type', $user->getMorphClass())->where('participant_id', $user->id);
    }

    private function assertOwns(User $user, string $conversationId): void
    {
        if (! $this->owned($user)->where('id', $conversationId)->exists()) {
            throw new RuntimeException(self::ERRORS['conversation']);
        }
    }
}
