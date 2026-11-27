<?php

namespace Tests\Feature;

use App\Actions\Users\SetVideoRating;
use App\Models\Folder;
use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\MediaLibrary;
use App\Services\JellyfinAccounts;
use App\Services\JellyfinClient;
use App\Services\JellyfinServer;
use App\Services\ServiceAccounts;
use App\Services\VideoSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * Vídeo (Jellyfin): the owner's one-off connection, private non-administrator accounts with only
 * their libraries and parental limit, and hardware acceleration limited to what the host offers.
 * A small in-memory Jellyfin answers the calls the adapter makes.
 */
class JellyfinProvisioningTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        config(['portal.services.video.enabled' => true, 'provisioning.video.enabled' => true,
            'portal.video_internal_url' => 'http://jellyfin.test', 'provisioning.video.hardware' => 'intel']);
        $this->owner->role = 'owner';
        $this->owner->save();
        $this->server = ['wizard' => false, 'users' => [], 'folders' => [], 'keys' => [], 'encoding' => ['HardwareAccelerationType' => 'none', 'EncoderAppPath' => '/usr/lib/jellyfin-ffmpeg/ffmpeg']];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->jellyfin($request));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root.'/.multisuite-media');
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    public function test_the_owner_connects_an_empty_server_once_and_cpu_is_never_forced_on_a_gpu_host(): void
    {
        $jellyfin = app(JellyfinServer::class);
        $this->assertSame('empty', $jellyfin->setupState());
        $this->assertNotContains('video', app(ServiceAccounts::class)->available(), 'No accounts before the connection.');

        $member = User::factory()->create(['role' => 'member']);
        $this->assertThrows(fn () => $jellyfin->connect($member, 'multisuite-admin', 'a-long-password'), HttpException::class);

        $jellyfin->connect($this->owner, 'multisuite-admin', 'a-long-password');

        $this->assertTrue($this->server['wizard']);
        $this->assertSame('connected', $jellyfin->setupState());
        $connection = ServiceConnection::findOrFail('video');
        $this->assertSame('admin-id', $connection->operator_id);
        $this->assertNotSame($this->server['keys'][0]['AccessToken'], $connection->getRawOriginal('secret'), 'The key is stored encrypted.');
        $this->assertSame('qsv', $this->server['encoding']['HardwareAccelerationType'], 'Intel host: Quick Sync by default.');
        $this->assertSame('/usr/lib/jellyfin-ffmpeg/ffmpeg', $this->server['encoding']['EncoderAppPath'], 'Other settings are kept.');
        $this->assertStringContainsString('Multisuite', $this->server['branding']['LoginDisclaimer']);
        $this->assertContains('video', app(ServiceAccounts::class)->available());
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, ServiceAccount::where('user_id', $member->id)->where('service', 'video')->value('status'));
        $this->assertSame('videos', array_key_last(ConnectedFolders::CONNECTIONS));
        $jellyfin->connect($this->owner, 'multisuite-admin', 'a-long-password');
        $this->assertCount(1, $this->server['keys']);
        $this->assertSame($connection->secret, ServiceConnection::findOrFail('video')->secret);
    }

    public function test_acceleration_choices_follow_the_detected_vendor_and_default_to_the_cpu(): void
    {
        $this->assertSame(['qsv', 'vaapi', 'none'], array_keys(JellyfinServer::accelerationChoices()));
        config(['provisioning.video.hardware' => 'amd']);
        $this->assertSame(['vaapi', 'none'], array_keys(JellyfinServer::accelerationChoices()));
        config(['provisioning.video.hardware' => 'nvidia']);
        $this->assertSame(['nvenc', 'none'], array_keys(JellyfinServer::accelerationChoices()));
        config(['provisioning.video.hardware' => 'unknown']);
        $this->assertSame(['none'], array_keys(JellyfinServer::accelerationChoices()));

        $this->connect();
        $this->assertSame('none', $this->server['encoding']['HardwareAccelerationType']);
        $this->assertThrows(fn () => app(JellyfinServer::class)->applyAcceleration('nvenc'), RuntimeException::class);
        $this->assertSame('none', app(JellyfinServer::class)->acceleration());
    }

    public function test_each_person_gets_a_private_non_administrator_account_with_their_parental_limit(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'lucia', 'service_access' => ['video'], 'video_max_rating' => 12]);
        app(ServiceAccounts::class)->enroll($member, ['video'], 'her-password-123');
        app(ServiceAccounts::class)->syncUser($member);

        $account = ServiceAccount::where('user_id', $member->id)->where('service', 'video')->sole();
        $this->assertSame('ready', $account->status);
        $remote = $this->server['users'][$account->external_id];
        $this->assertSame('lucia', $remote['Name']);
        $this->assertSame('her-password-123', $remote['password']);
        $policy = $remote['Policy'];
        $this->assertFalse($policy['IsAdministrator']);
        $this->assertFalse($policy['EnableAllFolders']);
        $this->assertSame(12, $policy['MaxParentalRating']);
        $this->assertContains('Movie', $policy['BlockUnratedItems']);
        $paths = array_map(fn (string $id): string => $this->server['folders'][$id]['Locations'][0], $policy['EnabledFolders']);
        sort($paths);
        $this->assertSame(['/common', '/personal/'.strtolower($account->remote_id)], $paths);

        // The administrator changes the limit: applied in Jellyfin and audited.
        $this->assertThrows(fn () => app(SetVideoRating::class)->handle($member, $member, null), AuthorizationException::class);
        $this->assertThrows(fn () => app(SetVideoRating::class)->handle($this->owner, $member, 13), ValidationException::class);
        app(SetVideoRating::class)->handle($this->owner, $member, null);
        $this->assertNull($this->server['users'][$account->external_id]['Policy']['MaxParentalRating']);
        $this->assertSame([], $this->server['users'][$account->external_id]['Policy']['BlockUnratedItems']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'user.updated', 'subject_id' => $member->id]);

        // Common library once something is published.
        app(JellyfinAccounts::class)->ensureCommonLibrary($account->fresh(), null, 'Vídeos para todos');
        $this->assertCount(3, $this->server['users'][$account->external_id]['Policy']['EnabledFolders']);

        app(JellyfinAccounts::class)->lock($account->fresh());
        $this->assertTrue($this->server['users'][$account->external_id]['Policy']['IsDisabled']);
        $this->assertSame([], $this->server['users'][$account->external_id]['Policy']['EnabledFolders']);
        $this->assertNotSame('her-password-123', $this->server['users'][$account->external_id]['password']);

        app(JellyfinAccounts::class)->remove($account->fresh());
        $this->assertArrayNotHasKey((string) $account->external_id, $this->server['users']);
    }

    public function test_a_video_upload_links_the_original_and_requests_a_private_library_scan(): void
    {
        $this->connect();
        $this->withoutDefer();
        app(ServiceAccounts::class)->resume($this->owner->fresh(), 'owner-password-123');
        $account = ServiceAccount::where('user_id', $this->owner->id)->where('service', 'video')->sole();
        app(ConnectedFolders::class)->ensure($this->owner);
        $folder = Folder::where('user_id', $this->owner->id)->where('media_link', 'videos')->sole();
        $scans = fn (): int => Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/Library/Refresh')->count();
        $before = $scans();

        $this->upload($this->owner, 'clip.mp4', "\x00\x00\x00\x18ftypisom".str_repeat("\x00", 32), $folder->id)->assertOk();

        $linked = $this->root.'/.multisuite-media/video/'.strtolower($account->remote_id).'/clip.mp4';
        $this->assertFileExists($linked);
        $this->assertSame(fileinode($this->root.'/.multisuite-private/'.$this->entry('clip.mp4')->upload_id.'.blob'), fileinode($linked));
        $this->assertGreaterThan($before, $scans());
        $afterUpload = $scans();
        app(MediaLibrary::class)->sync($this->owner);
        $this->assertSame($afterUpload, $scans());
    }

    public function test_existing_people_get_their_account_automatically_at_their_next_sign_in(): void
    {
        $member = User::factory()->create(['role' => 'member', 'username' => 'marta', 'service_access' => []]);
        $this->connect();
        $this->withoutDefer();

        app(ServiceAccounts::class)->resume($member->fresh(), 'marta-password-123');

        $this->assertContains('video', $member->fresh()->service_access, 'A service added to the portal joins at sign-in.');
        $account = ServiceAccount::where('user_id', $member->id)->where('service', 'video')->sole();
        $this->assertSame('ready', $account->status);
        $this->assertSame('marta-password-123', $this->server['users'][$account->external_id]['password']);
        $this->assertNull($account->pending_password, 'The password is not kept after success.');
    }

    public function test_an_account_left_half_created_cannot_be_used_and_can_still_be_locked(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'eva', 'service_access' => ['video']]);
        $this->server['failLibraries'] = true;
        app(ServiceAccounts::class)->enroll($member, ['video'], 'eva-password-123');
        app(ServiceAccounts::class)->syncUser($member);

        $account = ServiceAccount::where('user_id', $member->id)->where('service', 'video')->sole();
        $this->assertSame('pending', $account->status, 'Retried later.');
        $remote = $this->server['users'][$account->external_id];
        $this->assertTrue($remote['Policy']['EnableAllFolders'], 'Jellyfin default before the policy.');
        $this->assertNotSame('eva-password-123', $remote['password'], 'Nobody can sign in to the half-created account.');
        $this->assertSame(401, app(JellyfinClient::class)->http()->post('/Users/AuthenticateByName', ['Username' => 'eva', 'Pw' => 'eva-password-123'])->status());

        // Revocation does not depend on creating libraries.
        app(JellyfinAccounts::class)->lock($account);
        $this->assertTrue($this->server['users'][$account->external_id]['Policy']['IsDisabled']);
        $this->assertSame([], $this->server['users'][$account->external_id]['Policy']['EnabledFolders']);
        $this->assertFalse($this->server['users'][$account->external_id]['Policy']['EnableAllFolders']);
    }

    public function test_a_native_account_with_the_same_name_is_never_adopted(): void
    {
        $this->connect();
        $this->server['users']['native'] = ['Id' => 'native', 'Name' => 'pablo', 'password' => 'theirs', 'Policy' => ['IsAdministrator' => false]];
        $member = User::factory()->create(['role' => 'member', 'username' => 'pablo', 'service_access' => ['video']]);
        app(ServiceAccounts::class)->enroll($member, ['video'], 'chosen-password');
        app(ServiceAccounts::class)->syncUser($member);

        $this->assertSame('conflict', ServiceAccount::where('user_id', $member->id)->where('service', 'video')->value('status'));
        $this->assertSame('theirs', $this->server['users']['native']['password']);
    }

    public function test_opening_video_resumes_the_persons_own_session_without_a_second_sign_in(): void
    {
        config(['portal.services.video.url' => 'http://127.0.0.1:8096']);
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'nora', 'service_access' => ['video'], 'password' => 'nora-password-123']);
        $this->withoutDefer();

        // Without a prepared session (e.g. passkey sign-in) the password is asked once.
        $this->actingAs($member)->get('/services/video')->assertRedirect(route('video.confirm'));

        app(VideoSession::class)->prepare($member->fresh(), 'nora-password-123');
        $account = ServiceAccount::where('user_id', $member->id)->where('service', 'video')->sole();
        $this->assertSame('ready', $account->status, 'Created at sign-in, with no separate step.');
        $sealed = session('video.login');
        $this->assertIsString($sealed);
        $this->assertStringNotContainsString('session-', $sealed, 'Kept encrypted.');

        $launch = $this->actingAs($member)->withSession([Auth::guard('web')->getName() => $member->id, 'video.login' => $sealed])
            ->get('/services/video')->assertOk()->assertViewIs('video-launch')->assertSee('http://127.0.0.1:8096/_multisuite/launch', false);
        $ticket = $launch->viewData('ticket');
        $origin = rtrim((string) config('app.url'), '/');

        $this->post('/api/video/session', ['ticket' => $ticket], ['Origin' => 'https://foreign.test'])->assertForbidden();
        $enter = $this->post('/api/video/session', ['ticket' => $ticket], ['Origin' => $origin])->assertOk()
            ->assertSee('/_multisuite/enter.js', false)->assertDontSee('nora-password-123');
        $this->assertStringContainsString('no-store', (string) $enter->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'self'", (string) $enter->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('session-'.$account->external_id, $enter->getContent(), 'Her own Jellyfin session.');
        $this->assertStringContainsString('server-1', $enter->getContent());
        $this->post('/api/video/session', ['ticket' => $ticket], ['Origin' => $origin])->assertForbidden();

        // A revoked Jellyfin session is never handed over: back to the password page.
        $this->server['loggedOut'][] = Crypt::decrypt($sealed)['token'];
        $ticket = $this->actingAs($member)->withSession([Auth::guard('web')->getName() => $member->id, 'video.login' => $sealed])
            ->get('/services/video')->viewData('ticket');
        $this->post('/api/video/session', ['ticket' => $ticket], ['Origin' => $origin])->assertRedirect(rtrim((string) config('app.url'), '/').'/services/video/confirm');
    }

    public function test_each_browser_keeps_one_jellyfin_device_and_idle_ones_are_retired(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'irene', 'service_access' => ['video'], 'password' => 'irene-password-123']);
        $this->withoutDefer();
        request()->cookies->set(VideoSession::BROWSER_COOKIE, str_repeat('a', 40));
        app(VideoSession::class)->prepare($member->fresh(), 'irene-password-123');
        app(VideoSession::class)->prepare($member->fresh(), 'irene-password-123');
        $devices = array_values(array_filter($this->server['logins'], fn (?string $id): bool => str_starts_with((string) $id, JellyfinClient::DEVICE_PREFIX)));
        $this->assertCount(2, $devices);
        $this->assertSame($devices[0], $devices[1], 'Signing in again from the same browser reuses its device.');
        $this->assertStringNotContainsString(str_repeat('a', 40), $devices[0], 'The cookie itself never reaches Jellyfin.');

        $old = now()->subDays(45)->toIso8601String();
        $this->server['devices'] = [
            'multisuite-web-old' => ['Id' => 'multisuite-web-old', 'DateLastActivity' => $old],
            'multisuite-web-new' => ['Id' => 'multisuite-web-new', 'DateLastActivity' => now()->toIso8601String()],
            'multisuite-portal' => ['Id' => 'multisuite-portal', 'DateLastActivity' => $old],
            'tv-app' => ['Id' => 'tv-app', 'DateLastActivity' => $old],
        ];
        $this->artisan('portal:video-sessions-prune')->expectsOutput('Sesiones retiradas: 1')->assertSuccessful();
        $this->assertSame(['multisuite-web-new', 'multisuite-portal', 'tv-app'], array_keys($this->server['devices']));
    }

    public function test_formats_jellyfin_plays_are_accepted_only_in_the_videos_folder(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'lola', 'service_access' => ['video']]);
        app(ConnectedFolders::class)->ensure($member);
        $videos = Folder::where('user_id', $member->id)->where('media_link', 'videos')->firstOrFail();
        $avi = 'RIFF'.pack('V', 1000).'AVI LIST'.str_repeat("\0", 200);
        $mkv = "\x1a\x45\xdf\xa3\x93\x42\x82\x88matroska\x42\x87\x81\x04\x42\x85\x81\x02".str_repeat("\0", 200);

        // Outside «Vídeos» the reason is said, not a generic failure.
        $this->upload($member, 'fuera.avi', $avi)->assertUnprocessable()
            ->assertJsonPath('errors.upload.0', fn (string $message): bool => str_contains($message, 'solo se admite en la carpeta Vídeos'));

        $this->upload($member, 'viaje.avi', $avi, $videos->id)->assertOk();
        $this->upload($member, 'serie.mkv', $mkv, $videos->id)->assertOk();
        $this->assertSame(['video/x-msvideo', 'video/x-matroska'],
            [$this->entry('viaje.avi')->upload()->value('mime'), $this->entry('serie.mkv')->upload()->value('mime')]);

        // No browser player for it: an honest note and the way to Vídeo instead.
        $this->actingAs($member)->get(route('files.show', $this->entry('viaje.avi')))->assertOk()
            ->assertSee('El navegador no reproduce este formato')->assertSee('Ábrelo en Vídeo')->assertDontSee('<video', false);
    }

    public function test_signing_out_of_multisuite_ends_the_jellyfin_session_it_opened(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'olga', 'service_access' => ['video'], 'password' => 'olga-password-123']);
        $this->withoutDefer();
        app(VideoSession::class)->prepare($member->fresh(), 'olga-password-123');
        $sealed = session('video.login');

        $this->actingAs($member)->withSession(['video.login' => $sealed])->post('/logout');

        $this->assertContains(Crypt::decrypt($sealed)['token'], $this->server['loggedOut']);
    }

    public function test_an_administrator_account_is_never_handed_over(): void
    {
        $this->connect();
        $member = User::factory()->create(['role' => 'member', 'username' => 'pere', 'service_access' => ['video'], 'password' => 'pere-password-123']);
        $this->withoutDefer();
        app(ServiceAccounts::class)->resume($member->fresh(), 'pere-password-123');
        $account = ServiceAccount::where('user_id', $member->id)->where('service', 'video')->sole();
        $this->server['users'][$account->external_id]['Policy']['IsAdministrator'] = true; // promoted inside Jellyfin

        app(VideoSession::class)->prepare($member->fresh(), 'pere-password-123');

        $this->assertNull(session('video.login'));
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir($path.'/'.$name) && ! is_link($path.'/'.$name) ? $this->removeTree($path.'/'.$name) : unlink($path.'/'.$name);
            }
        }
        rmdir($path);
    }

    private function connect(): void
    {
        $this->server['wizard'] = true;
        $this->server['users']['admin-id'] = ['Id' => 'admin-id', 'Name' => 'multisuite-admin', 'password' => 'a-long-password', 'Policy' => ['IsAdministrator' => true]];
        app(JellyfinServer::class)->connect($this->owner, 'multisuite-admin', 'a-long-password');
    }

    private function jellyfin(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = $request->method();
        $body = $request->data();
        $admin = collect($this->server['keys'])->pluck('AccessToken')->contains(fn (string $key): bool => str_contains($request->header('Authorization')[0] ?? '', 'Token="'.$key.'"'))
            || str_contains($request->header('Authorization')[0] ?? '', 'Token="session-admin-id-');

        if ($path === '/System/Info/Public') {
            return Http::response(['StartupWizardCompleted' => $this->server['wizard'], 'Version' => '10.11.11', 'Id' => 'server-1', 'ServerName' => 'NAS']);
        }
        if (str_starts_with($path, '/Startup/')) {
            if ($this->server['wizard']) {
                return Http::response('', 401);
            }
            if ($path === '/Startup/User' && $method === 'POST') {
                $this->server['users']['admin-id'] = ['Id' => 'admin-id', 'Name' => $body['Name'], 'password' => $body['Password'], 'Policy' => ['IsAdministrator' => true]];
            }
            if ($path === '/Startup/Complete') {
                $this->server['wizard'] = true;
            }

            return Http::response('', 204);
        }
        if ($path === '/Users/AuthenticateByName') {
            preg_match('/DeviceId="([^"]+)"/', $request->header('Authorization')[0] ?? '', $device);
            $this->server['logins'][] = $device[1] ?? null;
            $user = collect($this->server['users'])->first(fn (array $row): bool => $row['Name'] === $body['Username'] && $row['password'] === $body['Pw'] && ! ($row['Policy']['IsDisabled'] ?? false));

            // A new token per sign-in, like Jellyfin: signing one out never ends another.
            return $user === null ? Http::response('', 401) : Http::response(['AccessToken' => 'session-'.$user['Id'].'-'.Str::random(8), 'User' => $this->dto($user)]);
        }
        if ($path === '/Sessions/Logout') {
            preg_match('/Token="([^"]+)"/', $request->header('Authorization')[0] ?? '', $token);
            $this->server['loggedOut'][] = $token[1] ?? null;

            return Http::response('', 204);
        }
        if ($path === '/Users/Me') {
            preg_match('/Token="(session-([^"]+)-[A-Za-z0-9]{8})"/', $request->header('Authorization')[0] ?? '', $token);
            $user = $this->server['users'][$token[2] ?? ''] ?? null;
            $revoked = in_array($token[1] ?? '', $this->server['loggedOut'] ?? [], true);

            return $user === null || $revoked || ($user['Policy']['IsDisabled'] ?? false) ? Http::response('', 401) : Http::response($this->dto($user));
        }
        if (! $admin) {
            return Http::response('', 401);
        }
        if ($path === '/Auth/Keys') {
            if ($method === 'POST') {
                $this->server['keys'][] = ['AppName' => $query['app'], 'AccessToken' => Str::random(32)];

                return Http::response('', 204);
            }

            return Http::response(['Items' => $this->server['keys']]);
        }
        if ($path === '/Devices') {
            if ($method === 'DELETE') {
                unset($this->server['devices'][$query['id']]);

                return Http::response('', 204);
            }

            return Http::response(['Items' => array_values($this->server['devices'] ?? [])]);
        }
        if ($path === '/System/Configuration/branding') {
            if ($method === 'POST') {
                $this->server['branding'] = $body;

                return Http::response('', 204);
            }

            return Http::response($this->server['branding'] ?? ['CustomCss' => '']);
        }
        if ($path === '/System/Configuration/encoding') {
            if ($method === 'POST') {
                $this->server['encoding'] = $body;

                return Http::response('', 204);
            }

            return Http::response($this->server['encoding']);
        }
        if ($path === '/Users' && $method === 'GET') {
            return Http::response(array_values(array_map(fn (array $user): array => $this->dto($user), $this->server['users'])));
        }
        if ($path === '/Users/New') {
            $id = Str::uuid()->getHex()->toString();
            $this->server['users'][$id] = ['Id' => $id, 'Name' => $body['Name'], 'password' => $body['Password'], 'Policy' => ['IsAdministrator' => false, 'EnableAllFolders' => true, 'EnabledFolders' => []]];

            return Http::response($this->dto($this->server['users'][$id]));
        }
        if ($path === '/Users/Password') {
            $this->server['users'][$query['userId']]['password'] = $body['NewPw'];

            return Http::response('', 204);
        }
        if ($path === '/Users' && $method === 'POST') {
            $this->server['users'][$query['userId']]['Name'] = $body['Name'];

            return Http::response('', 204);
        }
        if (preg_match('#^/Users/([^/]+)/Policy$#', $path, $match)) {
            $this->server['users'][$match[1]]['Policy'] = $body;

            return Http::response('', 204);
        }
        if (preg_match('#^/Users/([^/]+)$#', $path, $match) && $method === 'DELETE') {
            unset($this->server['users'][$match[1]]);

            return Http::response('', 204);
        }
        if ($path === '/Library/VirtualFolders') {
            if ($method === 'POST' && ($this->server['failLibraries'] ?? false)) {
                return Http::response('', 500);
            }
            if ($method === 'POST') {
                $id = 'lib-'.count($this->server['folders']);
                $this->server['folders'][$id] = ['Name' => $query['name'], 'ItemId' => $id, 'Locations' => [$query['paths']], 'CollectionType' => $query['collectionType']];

                return Http::response('', 204);
            }

            return Http::response(array_values($this->server['folders']));
        }
        if ($path === '/Library/Refresh') {
            return Http::response('', 204);
        }

        return Http::response('', 404);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    private function dto(array $user): array
    {
        return ['Id' => $user['Id'], 'Name' => $user['Name'], 'Policy' => $user['Policy']];
    }
}
