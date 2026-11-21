<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Ai\Agents\MultisuiteAssistant;
use App\Mcp\Servers\MultisuiteServer;
use App\Mcp\Tools\FilesReadExcerpt;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Files\FileText;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * MCP-03 (docs/MCP.md, D3): the assistant reads file text only when the owner allows it
 * and the person accepted it, only their own current files, 32 KiB at a time.
 */
class AssistantContentTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

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
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    private function settings(bool $content): void
    {
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 200000, $content);
    }

    private function read(User $user, int $fileId, int $offset = 0): TestResponse
    {
        return MultisuiteServer::actingAs($user)->tool(FilesReadExcerpt::class, ['file_id' => $fileId, 'offset' => $offset]);
    }

    /** A real one-page PDF whose text pdftotext can extract. */
    private static function pdf(string $text): string
    {
        $stream = 'BT /F1 18 Tf 20 80 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    public function test_reading_needs_the_owner_permission_and_the_persons_consent(): void
    {
        $this->settings(false);
        $this->upload($this->member, 'notas.txt', 'Lista de la compra: pan')->assertOk();
        $file = $this->entry('notas.txt');

        $this->read($this->member, $file->id)->assertNotRegistered();
        $this->actingAs($this->member);
        $direct = app(FilesReadExcerpt::class)->handle(new Request(['file_id' => $file->id]), app(AssistantAccess::class));
        $this->assertInstanceOf(Response::class, $direct);
        $this->assertSame(AssistantAccess::DENIALS['content_off'], (string) $direct->content());

        $this->settings(true);
        $this->read($this->member, $file->id)->assertNotRegistered();
        $this->assertSame(AssistantAccess::DENIALS['content_consent'],
            (string) app(FilesReadExcerpt::class)->handle(new Request(['file_id' => $file->id]), app(AssistantAccess::class))->content());

        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->read($this->member, $file->id)->assertOk()->assertSee('Lista de la compra: pan')->assertSee('untrusted_content');

        app(AssistantAccess::class)->setContentConsent($this->member, false);
        $this->read($this->member, $file->id)->assertNotRegistered();
    }

    public function test_only_the_persons_own_current_files_can_be_read(): void
    {
        $this->settings(true);
        app(AssistantAccess::class)->setContentConsent($this->owner, true);
        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->upload($this->member, 'diario.txt', 'Texto privado de Bea')->assertOk();
        $this->upload($this->owner, 'mio.txt', 'Texto del propietario')->assertOk();
        $theirs = $this->entry('diario.txt');
        $mine = $this->entry('mio.txt');

        // The owner's role opens nothing of anyone else's.
        $this->read($this->owner, $theirs->id)->assertHasErrors(['No existe o no es tuyo.'])->assertDontSee('Texto privado de Bea');
        $this->read($this->owner, $mine->id)->assertOk()->assertSee('Texto del propietario');

        app(PersonalFiles::class)->trash($this->owner, $mine->id);
        $this->read($this->owner, $mine->id)->assertHasErrors(['No existe o no es tuyo.'])->assertDontSee('Texto del propietario');
    }

    public function test_long_files_are_read_in_slices_and_other_formats_are_refused(): void
    {
        $this->settings(true);
        app(AssistantAccess::class)->setContentConsent($this->owner, true);
        $body = str_repeat('a', FileText::SLICE_BYTES - 1).'ñ'.str_repeat('b', 5000);
        $this->upload($this->owner, 'largo.txt', $body)->assertOk();
        $file = $this->entry('largo.txt');

        $first = app(FileText::class)->slice($this->owner, $file, 0);
        $this->assertSame(FileText::SLICE_BYTES - 1, strlen($first['text']), 'Cut before the split character.');
        $this->assertSame(FileText::SLICE_BYTES - 1, $first['next_offset']);
        $second = app(FileText::class)->slice($this->owner, $file, (int) $first['next_offset']);
        $this->assertSame('ñ'.str_repeat('b', 5000), $second['text']);
        $this->assertNull($second['next_offset']);
        $this->assertSame(strlen($body), $first['total_bytes']);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->upload($this->owner, 'foto.png', $png)->assertOk();
        $this->read($this->owner, $this->entry('foto.png')->id)->assertHasErrors(['solo puede leer texto']);
    }

    public function test_pdf_text_is_extracted_without_keeping_a_copy(): void
    {
        $this->settings(true);
        app(AssistantAccess::class)->setContentConsent($this->owner, true);
        $this->upload($this->owner, 'contrato.pdf', self::pdf('Hola desde el PDF'))->assertOk();

        $this->read($this->owner, $this->entry('contrato.pdf')->id)->assertOk()->assertSee('Hola desde el PDF')->assertSee('"format":"pdf"');
        $this->assertSame([], glob(storage_path('app/private/assistant-tmp/*')) ?: []);
    }

    public function test_a_pdf_that_cannot_be_converted_is_reported_as_a_failure(): void
    {
        $this->settings(true);
        app(AssistantAccess::class)->setContentConsent($this->owner, true);
        $this->upload($this->owner, 'roto.pdf', self::pdf('x'))->assertOk();
        Process::fake(fn () => Process::result('', 'Syntax Error', 1));

        $this->read($this->owner, $this->entry('roto.pdf')->id)->assertHasErrors(['No se ha podido sacar el texto']);
    }

    public function test_people_accept_or_withdraw_it_from_the_chat_page_and_the_agent_follows(): void
    {
        $this->settings(true);
        $this->actingAs($this->member);
        $toolNames = fn (): array => array_map(fn ($tool) => $tool->name(), [...(new MultisuiteAssistant($this->member))->tools()]);
        $this->assertNotContains('files_read_excerpt', $toolNames());

        Livewire::test('pages::assistant')->assertSee('Permitir que lea mis archivos')->call('setContent', true)->assertSee('Dejar de permitirlo');
        $this->assertTrue(app(AssistantAccess::class)->hasAcceptedContent($this->member));
        $this->assertContains('files_read_excerpt', $toolNames());

        Livewire::test('pages::assistant')->call('setContent', false);
        $this->assertFalse(app(AssistantAccess::class)->hasAcceptedContent($this->member));

        $this->settings(false);
        Livewire::test('pages::assistant')->assertDontSee('Permitir que lea mis archivos')->call('setContent', true)->assertForbidden();
    }
}
