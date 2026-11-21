<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SaveAssistantProvider;
use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Ai\Agents\MultisuiteAssistant;
use App\Ai\Tools\ApprovalRequiredTool;
use App\Mcp\Servers\MultisuiteServer;
use App\Mcp\Tools\FilesTrash;
use App\Mcp\Tools\FolderCreate;
use App\Models\Folder;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantChat;
use App\Services\Assistant\ChangeDescriber;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Mcp\Request;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * MCP-04 (docs/MCP.md): the assistant changes Mis archivos only with the person's approval in
 * the chat, only reversible changes, only their own files, and never through another path.
 */
class AssistantChangesTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        $this->owner->role = 'owner';
        $this->owner->save();
        $this->member = User::factory()->create(['role' => 'member']);
        app(StorageReservations::class)->setUserQuota($this->member, 500000);
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        app(SaveAssistantProvider::class)->handle($this->owner, null, 'anthropic', 'claude-sonnet-5-5', 'sk-test-0123456789abcdefWXYZ', 1, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    /** @param list<string> $changes */
    private function settings(array $changes): void
    {
        app(UpdateAssistantSettings::class)->handle($this->owner, true, [...array_keys(AssistantAccess::TOOLS), ...$changes], 200, 200000);
    }

    private function chat(): AssistantChat
    {
        return app(AssistantChat::class);
    }

    public function test_change_tools_are_off_until_the_owner_turns_them_on(): void
    {
        $this->settings([]);
        MultisuiteServer::actingAs($this->owner)->tool(FolderCreate::class, ['name' => 'Facturas'])->assertNotRegistered();
        $toolNames = fn (): array => array_map(fn ($tool) => $tool->name(), [...(new MultisuiteAssistant($this->owner))->tools()]);
        $this->assertNotContains('files_folder_create', $toolNames());

        $this->settings(['files_folder_create']);
        $this->assertContains('files_folder_create', $toolNames());
        $wrapped = collect([...(new MultisuiteAssistant($this->owner))->tools()])->first(fn ($tool) => $tool->name() === 'files_folder_create');
        $this->assertInstanceOf(ApprovalRequiredTool::class, $wrapped);
    }

    public function test_no_other_path_can_change_files_without_an_approval(): void
    {
        $this->settings(array_keys(AssistantAccess::WRITE_TOOLS));
        $this->upload($this->owner, 'viejo.pdf', self::PDF)->assertOk();
        $file = $this->entry('viejo.pdf');

        // Not even offered through the MCP server, and refused if called directly without approval.
        MultisuiteServer::actingAs($this->owner)->tool(FilesTrash::class, ['file_id' => $file->id])->assertNotRegistered();
        $this->actingAs($this->owner);
        foreach ([[FilesTrash::class, ['file_id' => $file->id]], [FolderCreate::class, ['name' => 'Colada']]] as [$tool, $arguments]) {
            $response = app($tool)->handle(new Request($arguments), app(AssistantAccess::class));
            $this->assertSame(AssistantAccess::DENIALS['approval'], (string) $response->content());
        }

        $this->assertNull($file->fresh()?->trashed_at);
        $this->assertSame(0, Folder::where('name', 'Colada')->count());
    }

    public function test_a_change_waits_for_approval_and_then_happens(): void
    {
        $this->settings(array_keys(AssistantAccess::WRITE_TOOLS));
        $this->upload($this->owner, 'viejo.pdf', self::PDF)->assertOk();
        $file = $this->entry('viejo.pdf');
        MultisuiteAssistant::fake([new ToolCall('call_trash', 'files_trash', ['file_id' => $file->id]), 'Hecho: viejo.pdf está en la papelera.']);

        $conversation = $this->chat()->send($this->owner, null, 'Manda viejo.pdf a la papelera')['conversation'];

        $this->assertNull($file->fresh()?->trashed_at, 'Nothing changes before the approval.');
        $pending = $this->chat()->pending($this->owner, $conversation);
        $this->assertCount(1, $pending);
        $this->assertSame('Mandar «viejo.pdf» a la papelera. Se puede recuperar desde allí.', $pending[0]['description']);

        try {
            $this->chat()->send($this->owner, $conversation, 'Otra cosa');
            $this->fail('A new question must wait for the decision.');
        } catch (RuntimeException $exception) {
            $this->assertSame(AssistantChat::ERRORS['pending'], $exception->getMessage());
        }
        try {
            $this->chat()->decide($this->member, $conversation, true);
            $this->fail('Only the owner of the conversation decides.');
        } catch (RuntimeException $exception) {
            $this->assertSame(AssistantChat::ERRORS['conversation'], $exception->getMessage());
        }
        $this->assertNull($file->fresh()?->trashed_at);

        $result = $this->chat()->decide($this->owner, $conversation, true);

        $this->assertNotNull($file->fresh()?->trashed_at);
        $this->assertSame('Hecho: viejo.pdf está en la papelera.', $result['text']);
        $this->assertSame([], $this->chat()->pending($this->owner, $conversation));
    }

    public function test_a_rejected_change_does_not_happen(): void
    {
        $this->settings(array_keys(AssistantAccess::WRITE_TOOLS));
        MultisuiteAssistant::fake([new ToolCall('call_folder', 'files_folder_create', ['name' => 'Recetas']), 'De acuerdo, no la creo.']);

        $conversation = $this->chat()->send($this->owner, null, 'Crea una carpeta Recetas')['conversation'];
        $this->assertSame('Crear la carpeta «Recetas» en «Mis archivos».', $this->chat()->pending($this->owner, $conversation)[0]['description']);

        $this->chat()->decide($this->owner, $conversation, false);

        $this->assertSame(0, Folder::where('name', 'Recetas')->count());
        $this->assertSame([], $this->chat()->pending($this->owner, $conversation));
    }

    public function test_approval_cards_name_only_the_persons_own_files(): void
    {
        $this->upload($this->member, 'ajeno.pdf', self::PDF)->assertOk();
        $theirs = $this->entry('ajeno.pdf');

        $this->assertSame('Mandar «un archivo que no existe o no es tuyo» a la papelera. Se puede recuperar desde allí.',
            app(ChangeDescriber::class)->describe($this->owner, 'files_trash', ['file_id' => $theirs->id]));
        $this->assertSame('Un cambio que Multisuite no reconoce: recházalo.', app(ChangeDescriber::class)->describe($this->owner, 'files_purge', []));
    }

    public function test_the_chat_page_shows_the_proposed_change_and_its_buttons(): void
    {
        $this->settings(array_keys(AssistantAccess::WRITE_TOOLS));
        MultisuiteAssistant::fake([new ToolCall('call_folder', 'files_folder_create', ['name' => 'Viajes'])]);
        $conversation = $this->chat()->send($this->owner, null, 'Crea la carpeta Viajes')['conversation'];

        $this->actingAs($this->owner);
        Livewire::withQueryParams(['c' => $conversation])->test('pages::assistant')
            ->assertSee('El asistente quiere hacer este cambio')
            ->assertSee('Crear la carpeta «Viajes» en «Mis archivos».')
            ->assertSee('Sí, hazlo')->assertSee('No, déjalo');
    }
}
