<?php

namespace App\Services\Assistant;

use App\Models\AssistantMember;
use App\Models\AssistantSetting;
use App\Models\AssistantToolCall;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may use the assistant right now and how much (docs/MCP.md § Autorización).
 * Every tool call asks here first, whether it comes from an MCP client or from the chat;
 * domain policies still apply afterwards inside each tool.
 */
class AssistantAccess
{
    /** @var array<string, string> Tool name => what it lets the assistant see, for the owner's settings page. */
    public const TOOLS = [
        'identity_me' => 'Tu cuenta: nombre, usuario y rol',
        'services_list' => 'Servicios que puede abrir cada persona y su estado',
        'storage_my_usage' => 'Espacio usado y disponible de cada persona',
        'files_list' => 'Carpetas y archivos de Mis archivos (nombres, tipos y tamaños; nunca el contenido)',
        'files_search' => 'Buscar en Mis archivos por nombre',
        'files_trash_list' => 'Ver qué hay en la papelera de cada persona',
        'music_search' => 'Buscar álbumes y canciones en Música (lo que cada persona puede escuchar)',
        'reading_search' => 'Buscar series y libros en Lectura (lo que cada persona puede leer)',
    ];

    /**
     * Tools that change Mis archivos (MCP-04). Off until the owner turns each one on, and every
     * call waits for the person's approval in the chat (ApprovalRequiredTool). Only reversible changes.
     *
     * @var array<string, string>
     */
    public const WRITE_TOOLS = [
        'files_folder_create' => 'Crear carpetas',
        'files_rename' => 'Cambiar el nombre de archivos',
        'files_trash' => 'Mandar archivos a la papelera',
        'files_restore' => 'Sacar archivos de la papelera',
    ];

    /** Tools that send file contents to the provider: governed by `content_allowed` and each person's consent (D3). */
    public const CONTENT_TOOLS = ['files_read_excerpt', 'semantic_search'];

    /** @var array<string, string> */
    public const DENIALS = [
        'guest' => 'Necesitas iniciar sesión en Multisuite.',
        'disabled' => 'El asistente está desactivado.',
        'unverified' => 'Verifica tu correo antes de usar el asistente.',
        'suspended' => 'Esta cuenta está suspendida.',
        'password' => 'Cambia tu contraseña inicial antes de usar el asistente.',
        'member' => 'No tienes acceso al asistente. Pídeselo a quien administra Multisuite.',
        'tool' => 'Esta herramienta del asistente está desactivada.',
        'budget' => 'Has llegado al límite diario de consultas del asistente. Vuelve a intentarlo mañana.',
        'content_off' => 'Quien administra Multisuite no permite que el asistente lea el contenido de los archivos.',
        'content_consent' => 'Para leer el contenido de tus archivos, primero acéptalo en la página del asistente.',
        'approval' => 'Este cambio solo se hace con tu aprobación desde la página del asistente.',
        'semantic_off' => 'La búsqueda por significado no está activada en Multisuite.',
        'semantic_provider' => 'La búsqueda por significado necesita un proveedor de IA que la admita (OpenAI, Gemini, Mistral u OpenRouter).',
        'semantic_unavailable' => 'La búsqueda por significado no está disponible ahora mismo. Prueba más tarde.',
    ];

    /** Reason code from DENIALS, or null when the person may use the assistant. */
    public function denial(?User $user): ?string
    {
        $settings = AssistantSetting::current();

        return match (true) {
            $user === null => 'guest',
            ! $settings->enabled => 'disabled',
            ! $user->hasVerifiedEmail() => 'unverified',
            $user->isSuspended() => 'suspended',
            $user->must_change_password => 'password',
            ! $this->isMember($user) => 'member',
            default => null,
        };
    }

    public function isMember(User $user): bool
    {
        return $user->role === 'owner' || AssistantMember::whereKey($user->id)->exists();
    }

    /**
     * Single answer to "may this person use this tool now?": reason code from DENIALS, or
     * null. Used to register MCP tools, to build the chat's tool list and inside handle().
     */
    public function toolDenial(?User $user, string $tool): ?string
    {
        $denial = $this->denial($user);
        if ($user === null || $denial !== null) {
            return $denial ?? 'guest';
        }
        $settings = AssistantSetting::current();
        if (in_array($tool, self::CONTENT_TOOLS, true)) {
            return $tool === 'semantic_search' && $settings->content_allowed && ! $settings->semantic_enabled
                ? 'semantic_off'
                : $this->contentDenial($user);
        }
        $enabled = array_key_exists($tool, self::WRITE_TOOLS)
            ? in_array($tool, $settings->tools ?? [], true)
            : array_key_exists($tool, self::TOOLS) && ($settings->tools === null || in_array($tool, $settings->tools, true));

        return $enabled ? null : 'tool';
    }

    /** Reason from DENIALS when the assistant may not read this person's file contents. */
    public function contentDenial(User $user): ?string
    {
        return match (true) {
            ! AssistantSetting::current()->content_allowed => 'content_off',
            ! $this->hasAcceptedContent($user) => 'content_consent',
            default => null,
        };
    }

    public function hasAcceptedContent(User $user): bool
    {
        return DB::table('assistant_consents')->where('user_id', $user->id)->exists();
    }

    /** The person's own choice: the owner allowing it is not enough (D3). */
    public function setContentConsent(User $user, bool $accepted): void
    {
        DB::transaction(function () use ($user, $accepted): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($this->hasAcceptedContent($user) === $accepted) {
                return;
            }
            User::whereKey($user->id)->increment('assistant_content_revision');
            if ($accepted) {
                DB::table('assistant_consents')->insert(['user_id' => $user->id, 'content_accepted_at' => now()]);
            } else {
                DB::table('assistant_consents')->where('user_id', $user->id)->delete();
                app(SemanticIndex::class)->forget($user);
            }
        });
    }

    public function setFolderExcluded(User $user, int $id, bool $excluded): void
    {
        DB::transaction(function () use ($user, $id, $excluded): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $folder = Folder::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if ((bool) $folder->assistant_excluded === $excluded) {
                return;
            }
            $folder->assistant_excluded = $excluded;
            $folder->save();
            User::whereKey($user->id)->increment('assistant_content_revision');
            if ($excluded) {
                app(SemanticIndex::class)->forget($user);
            }
        });
    }

    /** @return list<int> */
    public function excludedFolders(User $user): array
    {
        $children = $queue = $excluded = [];
        foreach (Folder::where('user_id', $user->id)->get(['id', 'parent_id', 'assistant_excluded']) as $folder) {
            $children[(int) $folder->parent_id][] = $folder->id;
            if ($folder->assistant_excluded) {
                $queue[] = $folder->id;
            }
        }
        while (($id = array_pop($queue)) !== null) {
            if (! isset($excluded[$id])) {
                $excluded[$id] = true;
                array_push($queue, ...($children[$id] ?? []));
            }
        }

        return array_keys($excluded);
    }

    public function usedToday(User $user): int
    {
        return AssistantToolCall::where('user_id', $user->id)->whereIn('outcome', AssistantToolCall::CONSUMED)
            ->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Reserves one call of today's budget under a lock on the person, so parallel calls
     * cannot overspend it. Null when the budget is used up (recorded as such).
     */
    public function begin(User $user, string $tool): ?AssistantToolCall
    {
        return DB::transaction(function () use ($user, $tool): ?AssistantToolCall {
            User::whereKey($user->id)->lockForUpdate()->first();
            $exhausted = $this->usedToday($user) >= AssistantSetting::current()->daily_calls;
            $call = $this->record($user, $tool, $exhausted ? 'budget' : 'running');

            return $exhausted ? null : $call;
        });
    }

    public function finish(AssistantToolCall $call, bool $failed, int $startedAt): void
    {
        $call->outcome = $failed ? 'error' : 'ok';
        $call->duration_ms = (int) min(4294967295, intdiv(hrtime(true) - $startedAt, 1000000));
        $call->save();
    }

    public function record(User $user, string $tool, string $outcome): AssistantToolCall
    {
        $call = new AssistantToolCall;
        $call->user_id = $user->id;
        $call->tool = mb_substr($tool, 0, 64);
        $call->outcome = $outcome;
        $call->save();

        return $call;
    }
}
