<?php

namespace Tests\Feature;

use App\Actions\Users\SetVideoRating;
use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Files\ConnectedFolders;
use App\Services\JellyfinAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    public function test_the_owner_connects_an_empty_server_once_and_cpu_is_never_forced_on_a_gpu_host(): void
    {
        $jellyfin = app(JellyfinAccounts::class);
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
        $this->assertContains('video', app(ServiceAccounts::class)->available());
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, ServiceAccount::where('user_id', $member->id)->where('service', 'video')->value('status'));
        $this->assertSame('videos', array_key_last(ConnectedFolders::CONNECTIONS));
    }

    public function test_acceleration_choices_follow_the_detected_vendor_and_default_to_the_cpu(): void
    {
        $this->assertSame(['qsv', 'vaapi', 'none'], array_keys(JellyfinAccounts::accelerationChoices()));
        config(['provisioning.video.hardware' => 'amd']);
        $this->assertSame(['vaapi', 'none'], array_keys(JellyfinAccounts::accelerationChoices()));
        config(['provisioning.video.hardware' => 'nvidia']);
        $this->assertSame(['nvenc', 'none'], array_keys(JellyfinAccounts::accelerationChoices()));
        config(['provisioning.video.hardware' => 'unknown']);
        $this->assertSame(['none'], array_keys(JellyfinAccounts::accelerationChoices()));

        $this->connect();
        $this->assertSame('none', $this->server['encoding']['HardwareAccelerationType']);
        $this->assertThrows(fn () => app(JellyfinAccounts::class)->applyAcceleration('nvenc'), RuntimeException::class);
        $this->assertSame('none', app(JellyfinAccounts::class)->acceleration());
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

    private function connect(): void
    {
        $this->server['wizard'] = true;
        $this->server['users']['admin-id'] = ['Id' => 'admin-id', 'Name' => 'multisuite-admin', 'password' => 'a-long-password', 'Policy' => ['IsAdministrator' => true]];
        app(JellyfinAccounts::class)->connect($this->owner, 'multisuite-admin', 'a-long-password');
    }

    private function jellyfin(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = $request->method();
        $body = $request->data();
        $admin = collect($this->server['keys'])->pluck('AccessToken')->contains(fn (string $key): bool => str_contains($request->header('Authorization')[0] ?? '', 'Token="'.$key.'"'))
            || str_contains($request->header('Authorization')[0] ?? '', 'Token="session-admin-id"');

        if ($path === '/System/Info/Public') {
            return Http::response(['StartupWizardCompleted' => $this->server['wizard'], 'Version' => '10.11.11']);
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
            $user = collect($this->server['users'])->first(fn (array $row): bool => $row['Name'] === $body['Username'] && $row['password'] === $body['Pw'] && ! ($row['Policy']['IsDisabled'] ?? false));

            return $user === null ? Http::response('', 401) : Http::response(['AccessToken' => 'session-'.$user['Id'], 'User' => $this->dto($user)]);
        }
        if ($path === '/Sessions/Logout') {
            return Http::response('', 204);
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
            if ($method === 'POST') {
                $id = 'lib-'.count($this->server['folders']);
                $this->server['folders'][$id] = ['Name' => $query['name'], 'ItemId' => $id, 'Locations' => [$query['paths']], 'CollectionType' => $query['collectionType']];

                return Http::response('', 204);
            }

            return Http::response(array_values($this->server['folders']));
        }
        if (preg_match('#^/Items/[^/]+/Refresh$#', $path)) {
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
