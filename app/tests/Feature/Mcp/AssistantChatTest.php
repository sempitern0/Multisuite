<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\RemoveAssistantProvider;
use App\Actions\Assistant\SaveAssistantProvider;
use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Ai\Agents\MultisuiteAssistant;
use App\Models\AdminAuditEvent;
use App\Models\AssistantProvider;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantChat;
use App\Services\Assistant\AssistantProviders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AnonymousAgent;
use RuntimeException;
use Tests\TestCase;

/**
 * MCP-02 (docs/MCP.md): providers configured by the owner with write-only encrypted keys,
 * and private conversations that fail honestly. Providers are faked: no real requests.
 */
class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-0123456789abcdefWXYZ';

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Ana Propietaria']);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Bea Miembro']);
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 200000);
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
    }

    private function provider(string $driver = 'anthropic', int $position = 1, bool $enabled = true): AssistantProvider
    {
        return app(SaveAssistantProvider::class)->handle($this->owner, null, $driver, AssistantProviders::DRIVERS[$driver]['model'], self::KEY, $position, $enabled);
    }

    private function chat(): AssistantChat
    {
        return app(AssistantChat::class);
    }

    public function test_the_key_is_encrypted_write_only_and_never_shown_or_audited(): void
    {
        $provider = $this->provider();

        $stored = (string) DB::table('assistant_providers')->where('id', $provider->id)->value('api_key');
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertSame(self::KEY, $provider->fresh()?->api_key);
        $this->assertSame('WXYZ', $provider->key_hint);
        $this->assertArrayNotHasKey('api_key', $provider->toArray());

        // Editing without a key keeps the stored one.
        app(SaveAssistantProvider::class)->handle($this->owner, $provider->id, 'anthropic', 'otro-modelo', null, 1, true);
        $this->assertSame(self::KEY, $provider->fresh()?->api_key);

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/assistant')
            ->assertOk()->assertSee('…WXYZ')->assertDontSee(self::KEY)->assertDontSee($stored);
        $this->assertSame([], AdminAuditEvent::where('action', 'assistant.provider_saved')->firstOrFail()->details);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode(AdminAuditEvent::all()->toArray()));
    }

    public function test_only_the_owner_manages_providers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(AuthorizationException::class);
        try {
            app(SaveAssistantProvider::class)->handle($admin, null, 'openai', 'gpt-6.1-sol', self::KEY, 1, true);
        } finally {
            $this->assertSame(0, AssistantProvider::count());
        }
    }

    public function test_providers_outside_the_list_or_with_a_short_key_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SaveAssistantProvider::class)->handle($this->owner, null, 'openai-compatible', 'x', 'corta', 1, true);
    }

    public function test_enabled_providers_are_used_in_order_and_removal_deletes_the_key(): void
    {
        $second = $this->provider('openai', 2);
        $first = $this->provider('anthropic', 1);
        $paused = $this->provider('gemini', 3, false);

        $this->assertSame([$first->runtimeName() => $first->model, $second->runtimeName() => $second->model], app(AssistantProviders::class)->chain());

        app(RemoveAssistantProvider::class)->handle($this->owner, $paused->id);
        $this->assertNull(DB::table('assistant_providers')->where('id', $paused->id)->value('api_key'));
    }

    public function test_the_connection_test_reports_a_working_provider(): void
    {
        AnonymousAgent::fake(['OK']);

        $this->assertSame(['result' => 'ok', 'status' => null], app(AssistantProviders::class)->test($this->provider()));
    }

    public function test_conversations_are_private_to_each_person(): void
    {
        $this->provider();
        MultisuiteAssistant::fake(['Te quedan 10 GB libres.']);

        $result = $this->chat()->send($this->owner, null, '¿Cuánto espacio me queda?');

        $this->assertSame('Te quedan 10 GB libres.', $result['text']);
        $this->assertSame(['user', 'assistant'], $this->chat()->messages($this->owner, $result['conversation'])->pluck('role')->all());
        $this->assertCount(1, $this->chat()->conversations($this->owner));
        $this->assertCount(0, $this->chat()->conversations($this->member));
        $this->assertSame(1, (int) DB::table('assistant_usage')->where('user_id', $this->owner->id)->value('prompts'));

        foreach ([
            fn () => $this->chat()->messages($this->member, $result['conversation']),
            fn () => $this->chat()->send($this->member, $result['conversation'], 'Continúo la tuya'),
            fn () => $this->chat()->delete($this->member, $result['conversation']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another person reached the conversation.');
            } catch (RuntimeException $exception) {
                $this->assertSame(AssistantChat::ERRORS['conversation'], $exception->getMessage());
            }
        }
        $this->assertCount(2, $this->chat()->messages($this->owner, $result['conversation']));

        $this->chat()->delete($this->owner, $result['conversation']);
        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
    }

    public function test_answers_are_escaped_while_they_stream(): void
    {
        $this->provider();
        MultisuiteAssistant::fake(['<img src=x onerror=alert(1)>']);
        $pieces = [];

        $this->chat()->send($this->owner, null, 'Hola', function (string $delta) use (&$pieces): void {
            $pieces[] = $delta;
        });

        $this->assertStringNotContainsString('<img', implode('', $pieces));
        $this->assertStringContainsString('&lt;img', implode('', $pieces));
    }

    public function test_it_refuses_clearly_without_access_providers_or_budget(): void
    {
        $expect = function (callable $attempt, string $message): void {
            try {
                $attempt();
                $this->fail('Expected a refusal.');
            } catch (RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        };

        $expect(fn () => $this->chat()->send($this->owner, null, 'Hola'), AssistantChat::ERRORS['providers']);

        $this->provider();
        app(SetAssistantMember::class)->handle($this->owner, $this->member, false);
        $expect(fn () => $this->chat()->send($this->member, null, 'Hola'), AssistantAccess::DENIALS['member']);

        DB::table('assistant_usage')->insert(['user_id' => $this->owner->id, 'day' => now()->toDateString(), 'prompts' => 9, 'input_tokens' => 150000, 'output_tokens' => 50000]);
        $expect(fn () => $this->chat()->send($this->owner, null, 'Hola'), AssistantChat::ERRORS['tokens']);
    }

    public function test_a_provider_failure_is_reported_as_a_failure(): void
    {
        $this->provider();
        MultisuiteAssistant::fake([fn () => throw new \LogicException('upstream said: secret details')]);

        try {
            $this->chat()->send($this->owner, null, 'Hola');
            $this->fail('Expected a failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(AssistantChat::ERRORS['failed'], $exception->getMessage());
        }
        $this->assertSame(0, DB::table('agent_conversation_messages')->where('role', 'assistant')->where('content', '!=', '')->count());
    }

    public function test_conversations_are_deleted_after_thirty_days_without_use(): void
    {
        $this->provider();
        MultisuiteAssistant::fake(['Uno.', 'Dos.']);
        $old = $this->chat()->send($this->owner, null, 'Antigua')['conversation'];
        $recent = $this->chat()->send($this->owner, null, 'Reciente')['conversation'];
        DB::table('agent_conversations')->where('id', $old)->update(['updated_at' => now()->subDays(31)]);

        $this->artisan('portal:assistant-prune')->assertSuccessful();

        $this->assertSame([$recent], DB::table('agent_conversations')->pluck('id')->all());
        $this->assertSame(0, DB::table('agent_conversation_messages')->where('conversation_id', $old)->count());
    }

    public function test_the_chat_page_is_only_for_people_with_access_and_blocks_remote_images(): void
    {
        $stranger = User::factory()->create(['role' => 'member']);

        $this->actingAs($stranger)->get('/assistant')->assertForbidden();
        $this->actingAs($this->member)->get('/assistant')->assertOk()->assertSee('Nueva conversación')
            ->assertHeader('Content-Security-Policy', "img-src 'self' data:");

        app(UpdateAssistantSettings::class)->handle($this->owner, false, array_keys(AssistantAccess::TOOLS), 200, 200000);
        $this->actingAs($this->owner)->get('/assistant')->assertForbidden();
    }
}
