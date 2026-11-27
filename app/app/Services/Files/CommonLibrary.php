<?php

namespace App\Services\Files;

use App\Enums\PublicationState;
use App\Enums\ServiceAccountStatus;
use App\Models\PrivateUpload;
use App\Models\Publication;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Provisioning\CommonLibraries;
use App\Services\Provisioning\ServiceProvisioners;
use App\Services\ServiceAccounts;
use App\Services\Storage\PrivateUploads;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The common library of each connected service (S04b): published files hard-linked into
 * {volume}/.multisuite-media/{service}/comun[/{section}] and that library granted to every account
 * with access to the service. The database is the source of truth: sync() converges.
 */
class CommonLibrary
{
    public function __construct(
        private PrivateUploads $uploads,
        private ServiceAccounts $accounts,
        private ServiceProvisioners $provisioners,
        private LinkMirror $mirror,
        private MediaScans $scans,
    ) {}

    /**
     * Links what is published (plus $include, minus $exclude) for every connection.
     *
     * @param  list<int>  $include  publications to link before they are confirmed
     * @param  list<int>  $exclude  publications to unlink before they are removed
     */
    public function sync(array $include = [], array $exclude = []): void
    {
        Cache::lock('common-library', 120)->block(30, function () use ($include, $exclude): void {
            foreach (array_keys(ConnectedFolders::CONNECTIONS) as $connection) {
                $definition = ConnectedFolders::definition($connection);
                $directory = $this->directory($definition['service'], $definition['section']);
                $desired = $this->desired($connection, $include, $exclude);
                $this->mirror->apply($directory, $desired);
                try {
                    $this->scans->changed($definition['service'], $directory, $desired);
                } catch (RuntimeException) {
                    // The next reconciliation retries the scan. Access grants are checked separately.
                    Log::warning('common library scan pending', ['service' => $definition['service']]);
                }
            }
        });
    }

    /**
     * Gives every account with access to the connection's service the common library. All or
     * nothing for the caller: any failure is reported (the scheduler retries the rest).
     */
    public function grant(string $connection): void
    {
        $definition = ConnectedFolders::definition($connection);
        $failed = false;
        foreach (ServiceAccount::where('service', $definition['service'])->where('status', ServiceAccountStatus::Ready->value)->get() as $account) {
            $user = User::find($account->user_id);
            if ($user !== null && $this->accounts->entitled($user, $definition['service'])) {
                $failed = ! $this->grantOne($account, $connection, true) || $failed;
            }
        }
        if ($failed) {
            throw new RuntimeException('unavailable');
        }
    }

    /**
     * Called from each person's media sync: access to the common library once something is
     * published. $publishing: called by grant() for the first publication, before it is confirmed.
     */
    public function grantOne(ServiceAccount $account, string $connection, bool $publishing = false): bool
    {
        $definition = ConnectedFolders::definition($connection);
        if (! config('provisioning.'.$definition['service'].'.enabled') || (! $publishing && ! $this->hasPublished($connection))) {
            return true;
        }
        $provisioner = $this->provisioners->for($definition['service']);
        if (! $provisioner instanceof CommonLibraries) {
            return true;
        }
        try {
            $provisioner->ensureCommonLibrary($account, $definition['section'], self::label($connection));

            return true;
        } catch (RuntimeException $exception) {
            Log::warning('common library not ready', ['service' => $definition['service'], 'reason' => $exception->getMessage()]);

            return false;
        }
    }

    public function hasPublished(string $connection): bool
    {
        return Publication::where('connection', $connection)->where('state', PublicationState::Published->value)->exists();
    }

    public static function label(string $connection): string
    {
        return ConnectedFolders::CONNECTIONS[$connection]['name'].' para todos';
    }

    private function directory(string $service, ?string $section): string
    {
        [$private] = $this->uploads->privateArea();
        $path = dirname($private).'/.multisuite-media/'.MediaLibrary::namespace($service).'/'.CommonLibraries::DIRECTORY.($section === null ? '' : '/'.$section);
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('No se puede preparar la biblioteca común.');
        }

        return $path;
    }

    /**
     * @param  list<int>  $include
     * @param  list<int>  $exclude
     * @return array<string, string>
     */
    private function desired(string $connection, array $include, array $exclude): array
    {
        $publications = Publication::where('connection', $connection)
            ->where(fn ($query) => $query->where('state', PublicationState::Published->value)->orWhereIn('id', $include))
            ->whereNotIn('id', $exclude)->orderBy('id')->get();
        $uploads = PrivateUpload::whereIn('id', $publications->pluck('upload_id'))->get()->keyBy('id');
        $desired = [];
        foreach ($publications as $publication) {
            $upload = $uploads->get($publication->upload_id);
            $path = $upload === null ? null : $this->uploads->connectedPath($upload);
            if ($path === null) {
                continue;
            }
            $name = LinkMirror::segment($publication->title);
            if (array_key_exists($name, $desired)) {
                $dot = strrpos($name, '.');
                $name = $dot === false || $dot === 0 ? $name.' ('.$publication->id.')' : substr($name, 0, $dot).' ('.$publication->id.')'.substr($name, $dot);
            }
            // Komga and Kavita skip loose files at the library root: one folder per item.
            $desired[($connection === 'music' ? '' : '_comun-'.$publication->id.'/').$name] = $path;
        }

        return $desired;
    }
}
