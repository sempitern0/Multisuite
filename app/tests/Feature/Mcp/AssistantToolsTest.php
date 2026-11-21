<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Mcp\Servers\MultisuiteServer;
use App\Mcp\Tools\FilesList;
use App\Mcp\Tools\FilesSearch;
use App\Mcp\Tools\IdentityMe;
use App\Mcp\Tools\ServicesList;
use App\Mcp\Tools\StorageMyUsage;
use App\Models\AdminAuditEvent;
use App\Models\AssistantToolCall;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * MCP-01 (docs/MCP.md): the assistant's read tools act as the signed-in person only,
 * are configured by the owner alone, and stop at once when access is withdrawn.
 */
class AssistantToolsTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

    private User $member;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        $this->owner->role = 'owner';
        $this->owner->save();
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Bea Miembro']);
        app(StorageReservations::class)->setUserQuota($this->member, 500000);
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    /** @param list<string>|null $tools */
    private function enable(?array $tools = null, int $daily = 200): void
    {
        app(UpdateAssistantSettings::class)->handle($this->owner, true, $tools ?? array_keys(AssistantAccess::TOOLS), $daily);
    }

    /** The chat path: laravel/ai calls handle() directly, without shouldRegister(). */
    private function callDirectly(User $user, string $tool, array $arguments = []): Response|ResponseFactory
    {
        $this->actingAs($user);

        return app($tool)->handle(new Request($arguments), app(AssistantAccess::class));
    }

    public function test_nothing_is_available_until_the_owner_turns_it_on(): void
    {
        MultisuiteServer::actingAs($this->owner)->tool(IdentityMe::class)->assertNotRegistered();
        $direct = $this->callDirectly($this->owner, IdentityMe::class);
        $this->assertInstanceOf(Response::class, $direct);
        $this->assertTrue($direct->isError());
        $this->assertSame(AssistantAccess::DENIALS['disabled'], (string) $direct->content());

        $this->enable();

        MultisuiteServer::actingAs($this->owner)->tool(IdentityMe::class)->assertOk()->assertSee($this->owner->name);
        MultisuiteServer::actingAs($this->owner)->tools()->assertRegistered([IdentityMe::class, ServicesList::class, StorageMyUsage::class, FilesList::class, FilesSearch::class]);
    }

    public function test_only_the_people_the_owner_chooses_can_use_it(): void
    {
        $this->enable();
        MultisuiteServer::actingAs($this->member)->tool(IdentityMe::class)->assertNotRegistered();
        $this->assertSame(AssistantAccess::DENIALS['member'], (string) $this->callDirectly($this->member, IdentityMe::class)->content());

        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        MultisuiteServer::actingAs($this->member)->tool(IdentityMe::class)->assertOk()->assertSee('Bea Miembro');

        app(SetAssistantMember::class)->handle($this->owner, $this->member, false);
        MultisuiteServer::actingAs($this->member)->tool(IdentityMe::class)->assertNotRegistered();
        $this->assertSame(['assistant.updated', 'assistant.member_added', 'assistant.member_removed'],
            AdminAuditEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_files_are_private_to_each_person_even_for_the_owner(): void
    {
        $this->enable();
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        $ownerFolder = app(PersonalFiles::class)->createFolder($this->owner, null, 'Privado del propietario');
        $this->upload($this->owner, 'secreto-propietario.pdf', self::PDF, $ownerFolder->id)->assertOk();
        $this->upload($this->member, 'notas-de-bea.pdf', self::PDF)->assertOk();

        MultisuiteServer::actingAs($this->member)->tool(FilesSearch::class, ['query' => 'secreto'])
            ->assertOk()->assertDontSee('secreto-propietario.pdf');
        MultisuiteServer::actingAs($this->member)->tool(FilesList::class, ['folder_id' => $ownerFolder->id])
            ->assertHasErrors(['No existe o no es tuyo.'])->assertDontSee('secreto-propietario.pdf');
        MultisuiteServer::actingAs($this->member)->tool(FilesList::class)
            ->assertOk()->assertSee('notas-de-bea.pdf')->assertDontSee('Privado del propietario');

        // The owner's role gives no way into someone else's files through the assistant.
        MultisuiteServer::actingAs($this->owner)->tool(FilesSearch::class, ['query' => 'notas'])
            ->assertOk()->assertDontSee('notas-de-bea.pdf');
        MultisuiteServer::actingAs($this->owner)->tool(FilesList::class, ['folder_id' => $ownerFolder->id])
            ->assertOk()->assertSee('secreto-propietario.pdf');
        MultisuiteServer::actingAs($this->owner)->tool(StorageMyUsage::class)
            ->assertOk()->assertSee((string) strlen(self::PDF))->assertDontSee($this->root);
    }

    public function test_suspension_and_a_pending_initial_password_stop_the_next_call(): void
    {
        $this->enable();
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        $this->assertFalse($this->callDirectly($this->member, IdentityMe::class)->responses()->first()?->isError() ?? true);

        $this->member->suspended_at = now();
        $this->member->save();
        // The session still holds the old model: access is re-read on every call.
        $this->assertSame(AssistantAccess::DENIALS['suspended'], (string) $this->callDirectly($this->member->fresh() ?? $this->member, IdentityMe::class)->content());

        $this->member->suspended_at = null;
        $this->member->must_change_password = true;
        $this->member->save();
        MultisuiteServer::actingAs($this->member)->tool(IdentityMe::class)->assertNotRegistered();
        $this->assertSame(AssistantAccess::DENIALS['password'], (string) $this->callDirectly($this->member, IdentityMe::class)->content());
    }

    public function test_switched_off_tools_and_the_daily_limit_are_enforced(): void
    {
        $this->enable(['identity_me'], 2);

        MultisuiteServer::actingAs($this->owner)->tool(FilesSearch::class, ['query' => 'algo'])->assertNotRegistered();
        $this->assertSame(AssistantAccess::DENIALS['tool'], (string) $this->callDirectly($this->owner, FilesSearch::class, ['query' => 'algo'])->content());

        MultisuiteServer::actingAs($this->owner)->tool(IdentityMe::class)->assertOk();
        MultisuiteServer::actingAs($this->owner)->tool(IdentityMe::class)->assertOk();
        MultisuiteServer::actingAs($this->owner)->tool(IdentityMe::class)->assertHasErrors([AssistantAccess::DENIALS['budget']]);

        $this->assertSame(2, app(AssistantAccess::class)->usedToday($this->owner));
        // Audit rows hold only tool and outcome: no arguments or results.
        $this->assertSame(['denied', 'ok', 'ok', 'budget'], AssistantToolCall::orderBy('id')->pluck('outcome')->all());
        $this->assertSame(['id', 'user_id', 'tool', 'outcome', 'duration_ms', 'created_at'], array_keys(AssistantToolCall::firstOrFail()->getAttributes()));
    }

    public function test_invalid_arguments_are_reported_as_failures(): void
    {
        $this->enable();

        MultisuiteServer::actingAs($this->owner)->tool(FilesSearch::class, ['query' => 'a'])->assertHasErrors();
        MultisuiteServer::actingAs($this->owner)->tool(FilesList::class, ['folder_id' => 'raíz'])->assertHasErrors();
        $this->assertSame(['error', 'error'], AssistantToolCall::orderBy('id')->pluck('outcome')->all());
    }

    public function test_listing_pages_through_files_in_name_order(): void
    {
        foreach (['b.pdf', 'a.pdf', 'c.pdf'] as $name) {
            $this->upload($this->owner, $name, self::PDF)->assertOk();
        }
        $files = app(PersonalFiles::class);

        $first = $files->page($this->owner, null, '', 1, 2);
        $second = $files->page($this->owner, null, '', 2, 2);

        $this->assertSame(['a.pdf', 'b.pdf'], $first['files']->pluck('name')->all());
        $this->assertTrue($first['more']);
        $this->assertSame(['c.pdf'], $second['files']->pluck('name')->all());
        $this->assertFalse($second['more']);
    }

    public function test_only_the_owner_configures_the_assistant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/assistant')->assertForbidden();
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/assistant')
            ->assertOk()->assertSee('Quién puede usarlo')->assertSee('Bea Miembro');

        $this->expectException(AuthorizationException::class);
        app(UpdateAssistantSettings::class)->handle($admin, true, ['identity_me'], 100);
    }

    public function test_an_admin_cannot_grant_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(AuthorizationException::class);
        try {
            app(SetAssistantMember::class)->handle($admin, $this->member, true);
        } finally {
            $this->assertFalse(app(AssistantAccess::class)->isMember($this->member));
        }
    }
}
