<?php

namespace App\Services\Files;

use App\Models\Folder;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Each person's fixed top-level folders linked to a service ("Música" → Navidrome, "Libros" and
 * "Cómics" → Kavita). Files uploaded anywhere inside one are stored unencrypted (one copy) and
 * appear only to that person in the service (MediaLibrary). The single place that knows which
 * folder goes to which service.
 */
class ConnectedFolders
{
    /**
     * @var array<string, array{service: string, name: string, section: string|null, icon: string, library: string, mimes: list<string>}>
     *
     * section: sub-folder of the person's directory in the service (null = the whole directory).
     * mimes: types accepted only here, on top of nas.allowed_mimes (comic archives are opened by
     * Kavita, never by the portal).
     */
    public const CONNECTIONS = [
        'music' => ['service' => 'music', 'name' => 'Música', 'section' => null, 'icon' => 'music', 'library' => 'Mi música', 'mimes' => []],
        'books' => ['service' => 'reading', 'name' => 'Libros', 'section' => 'libros', 'icon' => 'book', 'library' => 'Mis libros', 'mimes' => []],
        'comics' => ['service' => 'reading', 'name' => 'Cómics', 'section' => 'comics', 'icon' => 'book', 'library' => 'Mis cómics',
            'mimes' => ['application/zip', 'application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed', 'application/x-7z-compressed']],
    ];

    public function __construct(private ServiceAccounts $accounts) {}

    /** @return array{service: string, name: string, section: string|null, icon: string, library: string, mimes: list<string>} */
    public static function definition(string $connection): array
    {
        $definition = self::CONNECTIONS[$connection];
        if ($definition['service'] === 'reading' && config('portal.services.komga.enabled') && config('provisioning.komga.enabled')) {
            $definition['service'] = 'komga';
        }

        return $definition;
    }

    /** Creates the folders for the services the person may use now. Idempotent; never renames or moves existing ones. */
    public function ensure(User $user): void
    {
        foreach (array_keys(self::CONNECTIONS) as $connection) {
            $definition = self::definition($connection);
            if (! in_array($definition['service'], $this->accounts->available(), true) || ! $this->accounts->entitled($user, $definition['service'])) {
                continue;
            }
            if (Folder::where('user_id', $user->id)->where('media_link', $connection)->exists()) {
                continue;
            }
            DB::transaction(function () use ($user, $connection, $definition): void {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (Folder::where('user_id', $user->id)->where('media_link', $connection)->exists()) {
                    return;
                }
                // An ordinary folder may already use the name: never take over its (encrypted) content.
                $name = $definition['name'];
                $taken = fn (string $candidate): bool => Folder::where('user_id', $user->id)->whereNull('parent_id')
                    ->whereRaw('LOWER(name) = ?', [Str::lower($candidate)])->exists();
                for ($n = 2; $taken($name); $n++) {
                    $name = $definition['name'].' ('.($n === 2 ? 'conectada' : 'conectada '.$n).')';
                }
                $folder = new Folder;
                $folder->user_id = $user->id;
                $folder->parent_id = null;
                $folder->name = $name;
                $folder->media_link = $connection;
                $folder->save();
            });
        }
    }

    /** The connection of the folder or of its top-level ancestor, if any. */
    public function of(?Folder $folder): ?string
    {
        for ($depth = 0; $folder !== null && $depth < 32; $depth++) {
            if ($folder->parent_id === null) {
                return $folder->media_link;
            }
            $folder = Folder::find($folder->parent_id);
        }

        return null;
    }
}
