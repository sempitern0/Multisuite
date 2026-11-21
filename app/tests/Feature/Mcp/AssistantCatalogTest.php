<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Mcp\Servers\MultisuiteServer;
use App\Mcp\Tools\MusicSearch;
use App\Mcp\Tools\ReadingSearch;
use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MCP-06 (docs/MCP.md): catalogue search asks each service for the person's own libraries and
 * filters every result again, so nothing from another person's library can come back.
 */
class AssistantCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.services.music.enabled' => true,
            'provisioning.music.enabled' => true,
            'provisioning.music.url' => 'http://navidrome.test',
            'provisioning.music.username' => 'operator',
            'provisioning.music.password' => 'secret',
            'portal.services.komga.enabled' => true,
            'provisioning.komga.enabled' => true,
            'portal.komga_internal_url' => 'http://komga.test',
        ]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->ana = User::factory()->create(['role' => 'member', 'service_access' => ['music', 'komga'], 'email' => 'ana@example.com']);
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 200000);
        app(SetAssistantMember::class)->handle($this->owner, $this->ana, true);
    }

    /** @param array<string, string> $libraries */
    private function account(string $service, string $remoteId, string $status = 'ready', array $libraries = []): ServiceAccount
    {
        $account = new ServiceAccount;
        $account->user_id = $this->ana->id;
        $account->service = $service;
        $account->remote_id = $remoteId;
        $account->username = 'ana';
        $account->status = $status;
        if ($service === 'komga') {
            $account->external_id = $remoteId;
            $account->remote_email = $this->ana->email;
            $account->library_ids = $libraries;
        }
        $account->save();

        return $account;
    }

    public function test_music_search_only_returns_the_persons_libraries(): void
    {
        $this->account('music', 'nd-ana');
        Http::fake([
            'navidrome.test/auth/login' => Http::response(['token' => 'admin-token', 'isAdmin' => true]),
            'navidrome.test/api/user/nd-ana' => Http::response(['id' => 'nd-ana', 'userName' => 'ana', 'isAdmin' => false]),
            'navidrome.test/api/user/nd-ana/library' => Http::response([['id' => 1], ['id' => 3]]),
            'navidrome.test/api/album*' => Http::response([
                ['name' => 'Disco de Ana', 'albumArtist' => 'Ana', 'maxYear' => 2024, 'libraryId' => 3],
                ['name' => 'Disco de Bea', 'albumArtist' => 'Bea', 'maxYear' => 2025, 'libraryId' => 2],
            ]),
            'navidrome.test/api/song*' => Http::response([
                ['title' => 'Canción común', 'artist' => 'Varios', 'album' => 'Común', 'libraryId' => 1],
                ['title' => 'Canción de Bea', 'artist' => 'Bea', 'album' => 'Disco de Bea', 'libraryId' => 2],
            ]),
        ]);

        MultisuiteServer::actingAs($this->ana)->tool(MusicSearch::class, ['query' => 'disco'])
            ->assertOk()->assertSee('Disco de Ana')->assertSee('Canción común')
            ->assertDontSee('Disco de Bea')->assertDontSee('Canción de Bea');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/album?')
            && str_contains($request->url(), 'library_id=1&library_id=3') && ! str_contains($request->url(), 'library_id=2'));
    }

    public function test_reading_search_only_returns_the_libraries_komga_shares_with_the_person(): void
    {
        $connection = new ServiceConnection;
        $connection->service = 'komga';
        $connection->operator_id = 'operator';
        $connection->secret = 'secret-key';
        $connection->save();
        // The portal assigned two libraries; Komga currently shares only one of them.
        $this->account('komga', 'k-ana', 'ready', ['libros' => 'LIB-ANA', 'comun' => 'LIB-OLD']);
        Http::fake([
            'komga.test/api/v2/users' => Http::response([[
                'id' => 'k-ana', 'email' => 'ana@example.com', 'roles' => ['FILE_DOWNLOAD', 'PAGE_STREAMING'],
                'sharedAllLibraries' => false, 'sharedLibrariesIds' => ['LIB-ANA'],
            ]]),
            'komga.test/api/v1/series*' => Http::response(['content' => [
                ['name' => 'Saga de Ana', 'metadata' => ['title' => 'Saga de Ana'], 'booksCount' => 3, 'libraryId' => 'LIB-ANA'],
                ['name' => 'Saga ajena', 'metadata' => ['title' => 'Saga ajena'], 'booksCount' => 1, 'libraryId' => 'LIB-BEA'],
            ]]),
            'komga.test/api/v1/books*' => Http::response(['content' => [
                ['name' => 'Tomo 1', 'metadata' => ['title' => 'Tomo 1'], 'seriesTitle' => 'Saga de Ana', 'libraryId' => 'LIB-ANA'],
                ['name' => 'Tomo vetado', 'metadata' => ['title' => 'Tomo vetado'], 'seriesTitle' => 'Saga vieja', 'libraryId' => 'LIB-OLD'],
            ]]),
        ]);

        MultisuiteServer::actingAs($this->ana)->tool(ReadingSearch::class, ['query' => 'saga'])
            ->assertOk()->assertSee('Saga de Ana')->assertSee('Tomo 1')
            ->assertDontSee('Saga ajena')->assertDontSee('Tomo vetado');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/series?')
            && str_contains($request->url(), 'library_id=LIB-ANA') && ! str_contains($request->url(), 'LIB-OLD') && ! str_contains($request->url(), 'LIB-BEA'));
    }

    public function test_without_the_service_or_a_ready_account_it_explains_why(): void
    {
        $this->account('music', 'nd-ana', 'pending');

        MultisuiteServer::actingAs($this->ana)->tool(MusicSearch::class, ['query' => 'disco'])
            ->assertHasErrors(['aún no está lista']);

        $this->ana->service_access = ['music'];
        $this->ana->save();
        MultisuiteServer::actingAs($this->ana)->tool(ReadingSearch::class, ['query' => 'saga'])
            ->assertHasErrors(['No tienes Lectura']);
        Http::assertNothingSent();
    }

    public function test_a_service_that_does_not_answer_is_reported_without_details(): void
    {
        $this->account('music', 'nd-ana');
        Http::fake(['navidrome.test/auth/login' => Http::response('boom', 503)]);

        MultisuiteServer::actingAs($this->ana)->tool(MusicSearch::class, ['query' => 'disco'])
            ->assertHasErrors(['no responde ahora mismo'])->assertDontSee('boom');
    }
}
