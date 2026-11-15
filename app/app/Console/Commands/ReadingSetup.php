<?php

namespace App\Console\Commands;

use App\Services\KavitaAccounts;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Leaves Lectura (Kavita) ready for the portal: operator account and the default libraries.
 * Idempotent. In development it also creates the operator credentials in app/.env and registers
 * them as Kavita's first administrator; production requires both to be configured beforehand.
 * Never touches an administrator or library that already exists.
 */
class ReadingSetup extends Command
{
    protected $signature = 'portal:reading-setup {--wait=60 : Segundos máximos esperando a Kavita}';

    protected $description = 'Prepara Lectura (Kavita): cuenta de servicio del portal y bibliotecas iniciales';

    public function handle(): int
    {
        if (! config('provisioning.reading.enabled')) {
            $this->line('Lectura no está activada (KAVITA_PROVISIONING_ENABLED); nada que preparar.');

            return self::SUCCESS;
        }
        try {
            $url = ServiceUrl::validated((string) config('provisioning.reading.url'));
        } catch (RuntimeException) {
            $this->error('KAVITA_INTERNAL_URL no es válida.');

            return self::FAILURE;
        }
        if (! $this->credentials()) {
            return self::FAILURE;
        }
        $http = Http::baseUrl($url)->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(15);
        try {
            if (! $this->waitFor($http->timeout(3), max(0, (int) $this->option('wait')))) {
                $this->error('Kavita no responde en '.$url.'. Revisa `make reading-status`.');

                return self::FAILURE;
            }
            $exists = $http->get('/api/Admin/exists');
            if ($exists->json() === false) {
                $registered = $http->post('/api/Account/register', [
                    'username' => config('provisioning.reading.username'),
                    'password' => config('provisioning.reading.password'),
                ]);
                if (! $registered->successful()) {
                    $this->error('No se pudo crear la cuenta de servicio en Kavita.');

                    return self::FAILURE;
                }
                $this->info('Cuenta de servicio del portal creada como administradora de Kavita.');
            }
            $client = app(KavitaAccounts::class)->client();
            $this->libraries($client);
        } catch (ConnectionException) {
            $this->error('Kavita no responde en '.$url.'.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage() === 'configuration'
                ? 'Kavita ya tiene administrador y las credenciales KAVITA_ADMIN_* no entran como administrador. Configura la cuenta correcta en app/.env.'
                : 'Kavita no responde en '.$url.'.');

            return self::FAILURE;
        }

        $this->info('Lectura lista.');

        return self::SUCCESS;
    }

    /** Creates the default libraries that do not exist yet (matched by name) and starts a scan. */
    private function libraries(PendingRequest $client): void
    {
        $existing = array_column(array_filter((array) $client->get('/api/Library/libraries')->json(), 'is_array'), 'name');
        foreach ((array) config('provisioning.reading.libraries') as $library) {
            if (! is_array($library) || in_array($library['name'], $existing, true)) {
                continue;
            }
            $created = $client->post('/api/Library/create', [
                'id' => 0,
                'name' => $library['name'],
                'type' => $library['type'],
                'folders' => [$library['folder']],
                'folderWatching' => true,
                'includeInDashboard' => true,
                'includeInSearch' => true,
                'manageCollections' => true,
                'manageReadingLists' => true,
                'allowScrobbling' => false,
                'allowMetadataMatching' => false,
                'enableMetadata' => true,
                'removePrefixForSortName' => false,
                'inheritWebLinksFromFirstChapter' => false,
                'metadataProvider' => $library['provider'],
                'fileGroupTypes' => [1, 2, 3, 4],
                'excludePatterns' => [],
            ]);
            $created->successful()
                ? $this->info('Biblioteca «'.$library['name'].'» creada ('.$library['folder'].').')
                : $this->warn('No se pudo crear la biblioteca «'.$library['name'].'»; créala desde Kavita.');
        }
        $client->post('/api/Library/scan-all');
    }

    /** Development fills missing operator credentials in app/.env; production must configure them. */
    private function credentials(): bool
    {
        if (filled(config('provisioning.reading.username')) && filled(config('provisioning.reading.password'))) {
            return true;
        }
        if (app()->isProduction()) {
            $this->error('Configura KAVITA_ADMIN_USERNAME y KAVITA_ADMIN_PASSWORD en app/.env antes de preparar Lectura.');

            return false;
        }
        $values = ['KAVITA_ADMIN_USERNAME' => 'multisuite-portal', 'KAVITA_ADMIN_PASSWORD' => Str::password(32, symbols: false)];
        $env = base_path('.env');
        $contents = is_file($env) ? (string) file_get_contents($env) : '';
        foreach ($values as $key => $value) {
            if (preg_match('/^'.$key.'=\S+/m', $contents) === 1) {
                $this->error('app/.env ya tiene '.$key.' pero la configuración no la ve (¿config:cache antigua?).');

                return false;
            }
            $contents = preg_match('/^'.$key.'=$/m', $contents) === 1
                ? (string) preg_replace('/^'.$key.'=$/m', $key.'='.$value, $contents)
                : rtrim($contents, "\n")."\n".$key.'='.$value."\n";
        }
        if (file_put_contents($env, $contents) === false) {
            $this->error('No se pudo escribir app/.env.');

            return false;
        }
        config(['provisioning.reading.username' => $values['KAVITA_ADMIN_USERNAME'], 'provisioning.reading.password' => $values['KAVITA_ADMIN_PASSWORD']]);
        $this->info('Credenciales de la cuenta de servicio de Lectura creadas en app/.env.');

        return true;
    }

    private function waitFor(PendingRequest $http, int $seconds): bool
    {
        $deadline = time() + $seconds;
        do {
            try {
                if ($http->get('/api/health')->successful()) {
                    return true;
                }
            } catch (ConnectionException) {
                // Still starting.
            }
            if (time() >= $deadline) {
                return false;
            }
            sleep(2);
        } while (true);
    }
}
