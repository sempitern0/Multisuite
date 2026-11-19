<?php

namespace Tests\Integration;

use App\Models\Invitation;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\KomgaAccounts;
use App\Services\KomgaSession;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\PreparesMediaRoot;
use Tests\TestCase;
use ZipArchive;

/** Account creation, isolation and session cookies against the pinned native server. */
class KomgaAccountsTest extends TestCase
{
    use PreparesMediaRoot, RefreshDatabase;

    public function test_existing_and_new_users_get_private_libraries_and_native_sessions(): void
    {
        $url = getenv('KOMGA_TEST_URL');
        $root = getenv('MEDIA_TEST_ROOT');
        if (! $url || ! $root) {
            $this->markTestSkipped('Disposable Komga with the media directory mounted required.');
        }
        config(['portal.services.komga.enabled' => true, 'provisioning.komga.enabled' => true,
            'portal.komga_internal_url' => $url, 'portal.services.komga.url' => $url]);
        $this->prepareMediaRoot($root);
        $existing = User::factory()->create(['email' => 'existing@example.com', 'password' => 'existing-password']);
        $owner = User::factory()->create(['role' => 'owner', 'email' => 'operator@example.com', 'password' => 'operator-password']);
        $komga = app(KomgaAccounts::class);
        app(KomgaSession::class)->prepare($owner, 'operator-password');
        $this->assertTrue(KomgaAccounts::connected());
        $this->assertNotNull(session('komga.login'));
        $accounts = app(ServiceAccounts::class);
        $legacy = ServiceAccount::where('user_id', $existing->id)->where('service', 'komga')->firstOrFail();
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, $legacy->status);
        app(KomgaSession::class)->prepare($existing, 'existing-password');
        $this->assertNotNull(session('komga.login'));
        $this->assertSame('ready', $legacy->fresh()->status);
        $token = Str::random(64);
        $invitation = new Invitation;
        $invitation->email = 'invited@example.com';
        $invitation->role = 'member';
        $invitation->invited_by = $owner->id;
        $invitation->services = ['komga'];
        $invitation->token_hash = hash('sha256', $token);
        $invitation->expires_at = now()->addHour();
        $invitation->save();
        $this->post(URL::temporarySignedRoute('invitation.accept', now()->addHour(), ['token' => $token]), [
            'name' => 'Invited Reader', 'username' => 'invited',
            'password' => 'invited-password', 'password_confirmation' => 'invited-password',
        ])->assertRedirect(route('dashboard'));
        $new = User::where('email', 'invited@example.com')->firstOrFail();
        $this->assertNotNull(session('komga.login'));
        $account = ServiceAccount::where('user_id', $new->id)->where('service', 'komga')->firstOrFail();
        $this->assertSame('ready', $account->status);
        $this->assertNull($account->pending_password);
        $ticket = $this->get(route('services.open', 'komga'))->assertOk()->viewData('ticket');
        $launch = $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])
            ->assertStatus(303)->assertHeader('Location', '/');
        $cookie = collect($launch->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'KOMGA-SESSION');
        $this->assertNotNull($cookie);
        $this->assertSame($account->external_id, Http::withHeaders(['Cookie' => 'KOMGA-SESSION='.$cookie->getValue()])
            ->get($url.'/api/v2/users/me')->json('id'));
        app(KomgaSession::class)->prepare($new, 'invited-password');
        $this->assertNotNull(session('komga.login'));
        $native = Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v2/users/me');
        $this->assertTrue($native->successful(), $native->body());
        $this->assertNotContains('ADMIN', $native->json('roles'));
        $this->assertFalse($native->json('sharedAllLibraries'));
        $libraries = Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v1/libraries')->json();
        $this->assertCount(2, $libraries);
        $this->assertEqualsCanonicalizing(array_values($account->library_ids), array_column($libraries, 'id'));
        $this->assertEmpty(array_intersect(array_values($legacy->fresh()->library_ids), array_column($libraries, 'id')));
        $epub = $this->epub();
        $this->storeConnected($new, 'books', 'manual.epub', $epub);
        $this->assertGreaterThan(0, app(MediaLibrary::class)->sync($new));
        $count = count($komga->client()->get('/api/v1/libraries')->json());
        app(MediaLibrary::class)->sync($new);
        $this->assertCount($count, $komga->client()->get('/api/v1/libraries')->json());
        $book = null;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $books = Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v1/books')->json('content');
            $book = collect($books)->first(fn ($book) => $book['media']['status'] === 'READY');
            if ($book !== null) {
                break;
            }
            usleep(500000);
        }
        $this->assertNotNull($book, 'The private EPUB was not analyzed.');
        $fileUrl = $url.'/api/v1/books/'.$book['id'].'/file';
        $download = Http::withBasicAuth($new->email, 'invited-password')->get($fileUrl);
        $this->assertSame(hash('sha256', $epub), hash('sha256', $download->body()));
        $this->assertContains(Http::withBasicAuth($existing->email, 'existing-password')->get($fileUrl)->status(), [403, 404]);
        $accounts->changePassword($new, 'replacement-password');
        $this->assertTrue(Http::withBasicAuth($new->email, 'replacement-password')->get($url.'/api/v2/users/me')->successful());
        $this->assertSame(401, Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v2/users/me')->status());
        $komga->lock($account->fresh());
        $this->assertSame(401, Http::withBasicAuth($new->email, 'replacement-password')->get($url.'/api/v2/users/me')->status());
        $komga->remove($account->fresh());
        $komga->remove($account->fresh());
        $this->assertTrue(Http::withBasicAuth($existing->email, 'existing-password')->get($url.'/api/v2/users/me')->successful());
    }

    private function epub(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'komga-epub-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="book.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
        $zip->addFromString('book.opf', '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">urn:uuid:private-komga-ci</dc:identifier><dc:title>Private reading test</dc:title><dc:language>es</dc:language><meta property="dcterms:modified">2026-11-19T00:00:00Z</meta></metadata><manifest><item id="text" href="text.xhtml" media-type="application/xhtml+xml"/><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/></manifest><spine><itemref idref="text"/></spine></package>');
        $zip->addFromString('text.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Lectura</title></head><body>'.str_repeat('<p>Texto de prueba.</p>', 10000).'<p>FINAL DEL LIBRO</p></body></html>');
        $zip->addFromString('nav.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Índice</title></head><body><nav epub:type="toc"><ol><li><a href="text.xhtml">Texto</a></li></ol></nav></body></html>');
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);

        return $body;
    }

}
