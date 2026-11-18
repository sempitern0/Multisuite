<?php

namespace Tests\Integration;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Files\MediaLibrary;
use App\Services\KavitaAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparesMediaRoot;
use Tests\TestCase;

/** Full account lifecycle against a disposable Kavita 0.9.1 (CI: application.yml). */
class KavitaAccountsTest extends TestCase
{
    use PreparesMediaRoot, RefreshDatabase;

    private function login(string $url, string $username, string $password): Response
    {
        return Http::acceptJson()->post($url.'/api/Account/login', ['username' => $username, 'password' => $password]);
    }

    public function test_real_kavita_setup_and_account_lifecycle(): void
    {
        $url = getenv('KAVITA_TEST_URL');
        if (! $url) {
            $this->markTestSkipped('Disposable Kavita required.');
        }
        config([
            'portal.services.reading.enabled' => true,
            'provisioning.reading.enabled' => true,
            'provisioning.reading.url' => $url,
            'provisioning.reading.username' => 'ci-operator',
            'provisioning.reading.password' => 'ci-operator-password',
        ]);

        // First run registers the operator and creates the four libraries; a second run changes nothing.
        $this->artisan('portal:reading-setup', ['--wait' => 30])->assertSuccessful();
        $this->artisan('portal:reading-setup', ['--wait' => 30])->assertSuccessful();
        $libraries = array_column((array) app(KavitaAccounts::class)->client()->get('/api/Library/libraries')->json(), 'name');
        sort($libraries);
        $this->assertSame(['Cómics', 'Documentación técnica', 'Libros', 'Manga'], $libraries);

        $user = User::factory()->create(['email' => 'lectora@example.com']);
        $user->username = 'lectora';
        $user->service_access = ['reading'];
        $user->save();
        $accounts = app(ServiceAccounts::class);
        $accounts->enroll($user, ['reading'], 'first-password');
        $accounts->syncUser($user);
        $account = ServiceAccount::firstOrFail();
        $this->assertSame('ready', $account->status);
        $login = $this->login($url, 'lectora', 'first-password');
        $this->assertTrue($login->successful());
        $this->assertNotContains('Admin', (array) $login->json('roles'));

        $accounts->changePassword($user, 'second-password');
        $this->assertSame('ready', $account->fresh()?->status);
        $this->assertTrue($this->login($url, 'lectora', 'second-password')->successful());
        $this->assertSame(401, $this->login($url, 'lectora', 'first-password')->status());

        $kavita = app(KavitaAccounts::class);
        $kavita->rename($account, 'lectora2');
        $this->assertTrue($this->login($url, 'lectora2', 'second-password')->successful());

        $kavita->lock($account);
        $this->assertSame(401, $this->login($url, 'lectora2', 'second-password')->status());

        $user->username = 'lectora2';
        $user->save();
        $kavita->synchronize($account, 'third-password');
        $this->assertTrue($this->login($url, 'lectora2', 'third-password')->successful());

        $kavita->remove($account);
        $this->assertSame(401, $this->login($url, 'lectora2', 'third-password')->status());
        $kavita->remove($account);
    }

    public function test_real_kavita_shows_a_connected_folder_only_to_its_owner(): void
    {
        $url = getenv('KAVITA_TEST_URL');
        $root = getenv('MEDIA_TEST_ROOT');
        if (! $url || ! $root) {
            $this->markTestSkipped('Disposable Kavita with the media folder mounted required.');
        }
        config([
            'portal.services.reading.enabled' => true,
            'provisioning.reading.enabled' => true,
            'provisioning.reading.url' => $url,
            'provisioning.reading.username' => 'ci-operator',
            'provisioning.reading.password' => 'ci-operator-password',
        ]);
        $this->prepareMediaRoot($root);
        $this->artisan('portal:reading-setup', ['--wait' => 30])->assertSuccessful();
        $people = [];
        foreach (['ana', 'bea'] as $name) {
            $user = User::factory()->create(['email' => $name.'@example.com']);
            $user->username = $name;
            $user->service_access = ['reading'];
            $user->save();
            app(ServiceAccounts::class)->enroll($user, ['reading'], $name.'-password');
            app(ServiceAccounts::class)->syncUser($user);
            $people[$name] = $user;
        }
        $this->storeConnected($people['ana'], 'books', 'manual.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        app(MediaLibrary::class)->sync($people['ana']);
        app(MediaLibrary::class)->sync($people['bea']);

        $ana = ServiceAccount::where('user_id', $people['ana']->id)->firstOrFail();
        $this->assertFileExists($root.'/.multisuite-media/reading/'.strtolower($ana->remote_id).'/libros/_lectura-'.UserFile::where('user_id', $people['ana']->id)->where('name', 'manual.pdf')->firstOrFail()->id.'/manual.pdf');
        $client = app(KavitaAccounts::class)->client();
        $folder = '/personal/'.strtolower($ana->remote_id).'/libros';
        $library = collect((array) $client->get('/api/Library/libraries')->json())->first(fn ($library) => in_array($folder, (array) ($library['folders'] ?? []), true));
        $this->assertNotNull($library, 'Kavita created the personal library from the mounted folder.');
        $members = collect((array) $client->get('/api/Users', ['includePending' => 'true'])->json())->keyBy('username');
        $this->assertContains($library['id'], array_column($members['ana']['libraries'], 'id'));
        $this->assertNotContains($library['id'], array_column($members['bea']['libraries'], 'id'));
    }
}
