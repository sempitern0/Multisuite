<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Files\MediaLibrary;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-healing pass over every person's connected folders (MediaLibrary): recreates missing
 * links, removes stale ones and retries personal libraries a service could not create before.
 */
class MediaSync extends Command
{
    protected $signature = 'portal:media-sync {--user= : Solo esta persona (id)}';

    protected $description = 'Sincroniza las carpetas conectadas de Mis archivos con Música y Lectura';

    public function handle(MediaLibrary $media): int
    {
        if (! config('nas.uploads_enabled')) {
            return self::SUCCESS;
        }
        $failed = 0;
        $query = User::query()->whereHas('serviceAccounts')->orderBy('id');
        if ($this->option('user') !== null) {
            $query->whereKey((int) $this->option('user'));
        }
        foreach ($query->cursor() as $user) {
            try {
                $media->sync($user);
            } catch (Throwable $exception) {
                $failed++;
                $this->warn('Persona '.$user->id.': '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
