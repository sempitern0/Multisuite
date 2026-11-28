<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserFile;
use App\Models\UserStorageQuota;
use App\Services\Downloads\DownloadsAccess;
use App\Services\Downloads\StagedDownloads;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/** Descargas (docs/DOWNLOADS.md): who may open MeTube and how its downloads reach Mis archivos. */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;
    use UsesPrivateStorage;

    private string $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        $this->owner->role = Role::Owner->value;
        $this->owner->save();
        $this->staging = sys_get_temp_dir().'/multisuite-downloads-'.Str::uuid();
        mkdir($this->staging, 0700);
        config(['portal.services.downloads.enabled' => true, 'portal.services.downloads.url' => 'http://127.0.0.1:8084',
            'portal.downloads_staging_path' => $this->staging]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->staging));
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    private function launch(User $user): string
    {
        return $this->actingAs($user)->withSession([Auth::guard('web')->getName() => $user->id])
            ->get('/services/downloads')->assertOk()->assertSee('http://127.0.0.1:8084/_multisuite/launch')->viewData('ticket');
    }

    public function test_only_active_administrators_open_it_and_the_gateway_session_follows_the_account(): void
    {
        $member = User::factory()->create(['role' => Role::Member->value]);
        $this->actingAs($member)->get('/services/downloads')->assertForbidden();
        $this->actingAs($member)->get(route('downloads.index'))->assertForbidden();

        $origin = rtrim((string) config('app.url'), '/');
        $ticket = $this->launch($this->owner);
        $this->post('/api/downloads/session', ['ticket' => $ticket], ['Origin' => 'https://foreign.test'])
            ->assertStatus(303)->assertHeader('Location', $origin.'/dashboard');
        $this->post('/api/downloads/session', ['ticket' => $ticket], ['Origin' => $origin])
            ->assertStatus(303)->assertHeader('Location', $origin.'/dashboard'); // The refused attempt used it.

        $ticket = $this->launch($this->owner);
        $response = $this->post('/api/downloads/session', ['ticket' => $ticket], ['Origin' => $origin])->assertStatus(303)->assertHeader('Location', '/');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === DownloadsAccess::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $session = (string) $cookie->getValue();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $session);
        $this->post('/api/downloads/session', ['ticket' => $ticket], ['Origin' => $origin])->assertHeader('Location', $origin.'/dashboard');

        $check = fn (string $value) => $this->call('GET', '/api/downloads/check', server: ['HTTP_X_MULTISUITE_DOWNLOADS' => $value]);
        $check($session)->assertNoContent();
        $check('')->assertUnauthorized();
        $check(substr($session, 0, -4).'AAAA')->assertUnauthorized();

        $this->owner->password = 'otra-contraseña-nueva';
        $this->owner->save();
        $check($session)->assertUnauthorized(); // A new password ends every gateway session.
    }

    public function test_a_finished_download_is_saved_into_mis_archivos_with_quota_or_discarded(): void
    {
        mkdir($this->staging.'/.metube', 0700);
        file_put_contents($this->staging.'/.metube/queue', 'state');
        file_put_contents($this->staging.'/a medias.mp4.part', 'partial');
        $song = 'ID3'.str_repeat('a', 4000);
        file_put_contents($this->staging.'/cancion.mp3', $song);
        mkdir($this->staging.'/Lista', 0700);
        file_put_contents($this->staging.'/Lista/otra.mp3', 'ID3'.str_repeat('b', 900));
        symlink('/etc/hostname', $this->staging.'/enlace.mp3');

        $items = app(StagedDownloads::class)->all();
        $this->assertEqualsCanonicalizing(['cancion.mp3', 'otra.mp3'], array_column($items, 'name'), 'Hidden, partial and linked files are never offered.');
        $songId = collect($items)->firstWhere('name', 'cancion.mp3')['id'];
        $otherId = collect($items)->firstWhere('name', 'otra.mp3')['id'];

        $page = Livewire::actingAs($this->owner)->test('pages::admin.downloads')->assertSee('cancion.mp3')->assertSee('Lista / otra.mp3');
        $page->call('save', $songId)->assertHasNoErrors();
        $file = UserFile::where('user_id', $this->owner->id)->where('name', 'cancion.mp3')->firstOrFail();
        $this->assertSame(strlen($song), UserStorageQuota::findOrFail($this->owner->id)->used_bytes);
        $this->assertFileDoesNotExist($this->staging.'/cancion.mp3');
        $this->assertSame($song, $this->get(route('files.content', $file->id))->streamedContent());

        $page->call('save', 'f'.str_repeat('0', 31))->assertHasErrors('download');

        app(StorageReservations::class)->setUserQuota($this->owner, 500);
        $page->call('save', $otherId)->assertHasErrors('download');
        $this->assertFileExists($this->staging.'/Lista/otra.mp3', 'Without space it stays staged.');
        $this->assertSame(strlen($song), UserStorageQuota::findOrFail($this->owner->id)->used_bytes);
        $this->assertSame(0, UserStorageQuota::findOrFail($this->owner->id)->reserved_bytes, 'Nothing stays reserved.');

        $page->call('discard', $otherId)->assertHasNoErrors();
        $this->assertFileDoesNotExist($this->staging.'/Lista/otra.mp3');
        $this->assertFileExists('/etc/hostname');
    }
}
