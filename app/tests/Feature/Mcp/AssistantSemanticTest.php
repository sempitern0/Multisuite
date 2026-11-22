<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SaveAssistantProvider;
use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Mcp\Servers\MultisuiteServer;
use App\Mcp\Tools\SemanticSearch;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\SemanticIndex;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * MCP-07 (docs/MCP.md): search by meaning over each person's own current files, only with
 * their consent, stored encrypted, and gone as soon as consent or the feature goes.
 */
class AssistantSemanticTest extends TestCase
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
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 1000000, true, true);
        app(SaveAssistantProvider::class)->handle($this->owner, null, 'gemini', 'gemini-3.8-flash', 'sk-test-0123456789abcdefWXYZ', 1, true);
        $this->fakeEmbeddings();
    }

    /** Deterministic "meaning": one axis per topic, so the closest text is predictable. */
    private function fakeEmbeddings(): void
    {
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(function (string $text): array {
            $vector = array_fill(0, SemanticIndex::DIMENSIONS, 0.0);
            $vector[0] = str_contains(mb_strtolower($text), 'luz') || str_contains(mb_strtolower($text), 'electricidad') ? 1.0 : 0.0;
            $vector[1] = str_contains(mb_strtolower($text), 'coche') ? 1.0 : 0.0;
            $vector[2] = 0.05;

            return $vector;
        }, $prompt->inputs));
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    private function index(): SemanticIndex
    {
        return app(SemanticIndex::class);
    }

    public function test_it_finds_the_persons_files_by_meaning_and_stores_text_encrypted(): void
    {
        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->upload($this->member, 'recibo-enero.txt', 'Recibo de la electricidad de enero: 80 euros.')->assertOk();
        $this->upload($this->member, 'taller.txt', 'Revisión del coche en el taller.')->assertOk();

        $this->assertSame(2, $this->index()->sync($this->member));
        $this->assertSame(0, $this->index()->sync($this->member), 'Unchanged files are not prepared twice.');

        $results = $this->index()->search($this->member, 'factura de la luz');
        $this->assertSame('recibo-enero.txt', $results[0]['name']);
        $this->assertStringContainsString('electricidad', $results[0]['excerpt']);
        $this->assertStringNotContainsString('electricidad', (string) json_encode(DB::table('assistant_chunks')->pluck('content')->all()));

        MultisuiteServer::actingAs($this->member)->tool(SemanticSearch::class, ['query' => 'factura de la luz'])
            ->assertOk()->assertSee('recibo-enero.txt');
    }

    public function test_nobody_else_reaches_the_index_and_trashed_files_drop_out(): void
    {
        app(AssistantAccess::class)->setContentConsent($this->member, true);
        app(AssistantAccess::class)->setContentConsent($this->owner, true);
        $this->upload($this->member, 'luz-bea.txt', 'Factura de la luz de Bea')->assertOk();
        $this->upload($this->owner, 'coche.txt', 'Seguro del coche')->assertOk();
        $this->index()->sync($this->member);
        $this->index()->sync($this->owner);

        $this->assertSame([], array_filter($this->index()->search($this->owner, 'luz'), fn (array $hit): bool => $hit['name'] === 'luz-bea.txt'));

        $file = $this->entry('luz-bea.txt');
        app(PersonalFiles::class)->trash($this->member, $file->id);
        $this->assertSame([], $this->index()->search($this->member, 'luz'), 'Trashed at once, before the next pass.');
        $this->index()->sync($this->member);
        $this->assertSame(0, DB::table('assistant_chunks')->where('user_file_id', $file->id)->count());
    }

    public function test_withdrawing_consent_or_turning_it_off_removes_the_index(): void
    {
        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->upload($this->member, 'luz.txt', 'Factura de la luz')->assertOk();
        $this->index()->sync($this->member);
        $this->assertSame(1, $this->index()->stats($this->member)['files']);

        app(AssistantAccess::class)->setContentConsent($this->member, false);
        $this->assertSame(0, DB::table('assistant_chunks')->where('user_id', $this->member->id)->count());
        MultisuiteServer::actingAs($this->member)->tool(SemanticSearch::class, ['query' => 'luz'])->assertNotRegistered();

        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->index()->sync($this->member);
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 1000000, true, false);
        $this->artisan('portal:assistant-index')->assertSuccessful();
        $this->assertSame(0, DB::table('assistant_chunks')->count());
    }

    public function test_parent_exclusion_removes_existing_passages_and_skips_descendants(): void
    {
        $access = app(AssistantAccess::class);
        $access->setContentConsent($this->member, true);
        $files = app(PersonalFiles::class);
        $parent = $files->createFolder($this->member, null, 'Personal');
        $child = $files->createFolder($this->member, $parent->id, 'Facturas');
        $this->upload($this->member, 'luz.txt', 'Factura de la luz', $child->id)->assertOk();
        $this->assertSame(1, $this->index()->sync($this->member));
        $access->setFolderExcluded($this->member, $parent->id, true);
        $this->assertSame(0, $this->index()->stats($this->member)['chunks']);
        $this->assertSame(0, $this->index()->sync($this->member));
        $access->setFolderExcluded($this->member, $parent->id, false);
        $this->assertSame(1, $this->index()->sync($this->member));
    }

    public function test_consent_changed_during_embedding_discards_the_result(): void
    {
        $access = app(AssistantAccess::class);
        $access->setContentConsent($this->member, true);
        $this->upload($this->member, 'nota.txt', 'Contenido privado')->assertOk();
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($access): array {
            $access->setContentConsent($this->member, false);
            $access->setContentConsent($this->member, true);

            return array_fill(0, count($prompt->inputs), array_fill(0, SemanticIndex::DIMENSIONS, 0.1));
        });
        $this->assertSame(0, $this->index()->sync($this->member));
        $this->assertSame(0, $this->index()->stats($this->member)['chunks']);
    }

    public function test_a_provider_outage_leaves_files_pending_for_the_next_pass(): void
    {
        app(AssistantAccess::class)->setContentConsent($this->member, true);
        $this->upload($this->member, 'luz.txt', 'Factura de la luz')->assertOk();
        Embeddings::fake(fn (): array => throw new RuntimeException('Proveedor caído'));

        try {
            $this->index()->sync($this->member);
            $this->fail('An outage must stop the pass, not skip the file.');
        } catch (RuntimeException $exception) {
            $this->assertSame('semantic_unavailable', $exception->getMessage());
        }
        $this->artisan('portal:assistant-index')->assertSuccessful();
        $this->assertSame(0, DB::table('assistant_indexed_files')->count(), 'Nothing is marked as prepared.');

        $this->fakeEmbeddings();
        $this->assertSame(1, $this->index()->sync($this->member));
        $this->assertSame('luz.txt', $this->index()->search($this->member, 'luz')[0]['name']);
    }

    public function test_it_needs_a_provider_that_offers_embeddings(): void
    {
        DB::table('assistant_providers')->update(['driver' => 'anthropic']);
        app(AssistantAccess::class)->setContentConsent($this->member, true);

        $this->assertSame('semantic_provider', $this->index()->denial($this->member));
        MultisuiteServer::actingAs($this->member)->tool(SemanticSearch::class, ['query' => 'luz'])
            ->assertHasErrors([AssistantAccess::DENIALS['semantic_provider']]);
    }
}
