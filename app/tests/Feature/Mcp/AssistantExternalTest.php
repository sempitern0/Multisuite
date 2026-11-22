<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * MCP-05 (docs/MCP.md): external MCP clients use OAuth tokens with the mcp:use scope, and every
 * request re-checks the owner's switch and the person. Change tools are never offered here.
 */
class AssistantExternalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->member = User::factory()->create(['role' => 'member']);
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
    }

    private function settings(bool $external): void
    {
        app(UpdateAssistantSettings::class)->handle($this->owner, true,
            [...array_keys(AssistantAccess::TOOLS), ...array_keys(AssistantAccess::WRITE_TOOLS)], 200, 200000, false, false, $external);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ]);
    }

    public function test_discovery_endpoints_describe_the_oauth_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_client_registration_needs_the_owner_switch_and_is_throttled(): void
    {
        $client = ['client_name' => 'Prueba', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];
        $this->settings(false);
        $this->postJson('/oauth/register', $client)->assertForbidden();
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('registration_endpoint', url('/oauth/register'));

        $this->settings(true);
        $statuses = [];
        for ($i = 0; $i < 10; $i++) {
            $statuses[] = $this->postJson('/oauth/register', $client)->status();
        }
        // 10 requests a minute per address across discovery and registration (two used above).
        $this->assertNotSame(429, $statuses[0]);
        $this->assertNotContains(403, $statuses);
        $this->assertSame(429, end($statuses));
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->settings(true);

        $this->rpc('tools/list')->assertUnauthorized();
    }

    public function test_the_owner_switch_and_the_persons_access_are_checked_on_every_request(): void
    {
        $this->settings(false);
        Passport::actingAs($this->member, ['mcp:use'], 'api');
        $this->rpc('tools/list')->assertForbidden();

        $this->settings(true);
        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $names = array_column(is_array($tools) ? $tools : [], 'name');
        $this->assertContains('identity_me', $names);
        $this->assertNotContains('files_trash', $names, 'Changes are approved only in the portal chat.');
        $this->assertNotContains('files_folder_create', $names);

        $this->member->suspended_at = now();
        $this->member->save();
        $this->rpc('tools/list')->assertForbidden();

        $this->member->suspended_at = null;
        $this->member->save();
        app(SetAssistantMember::class)->handle($this->owner, $this->member, false);
        $this->rpc('tools/list')->assertForbidden();
    }

    public function test_a_token_without_the_mcp_scope_is_refused(): void
    {
        $this->settings(true);
        Passport::actingAs($this->owner, [], 'api');

        $this->rpc('tools/list')->assertForbidden();
    }

    public function test_the_consent_screen_only_offers_to_connect_when_it_is_allowed(): void
    {
        $client = new Client;
        $client->forceFill(['id' => 'client-1', 'name' => 'Claude']);
        $render = fn (User $user): string => View::make('pages.oauth-authorize', [
            'client' => $client, 'user' => $user, 'scopes' => [], 'request' => Request::create('/oauth/authorize?state=xyz'), 'authToken' => 'token-123',
        ])->render();

        $this->settings(false);
        $closed = $render($this->member);
        $this->assertStringContainsString('no permite conectar aplicaciones externas', $closed);
        $this->assertStringNotContainsString('>Conectar<', $closed);

        $this->settings(true);
        $open = $render($this->member);
        $this->assertStringContainsString('¿Conectar «Claude»?', $open);
        $this->assertStringContainsString('No podrá cambiar nada', $open);
        $this->assertStringContainsString('name="auth_token" value="token-123"', $open);

        // Without access to the assistant the approve form is not rendered at all.
        $stranger = User::factory()->create(['role' => 'member']);
        $denied = $render($stranger);
        $this->assertStringNotContainsString('No podrá cambiar nada', $denied);
        $this->assertSame(1, substr_count($denied, 'name="auth_token"'), 'Only the deny form.');
    }
}
