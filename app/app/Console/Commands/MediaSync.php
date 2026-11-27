<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Files\CommonLibrary;
use App\Services\Files\MediaLibrary;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-healing pass over every person's connected folders (MediaLibrary): recreates missing
 * links, removes stale ones and retries personal libraries a service could not create before.
 * Also converges the common library (S04b), so a withdrawn or deleted publication whose unlink
 * failed stops being shown to everyone on the next run.
 */
class MediaSync extends Command
{
    protected $signature = 'portal:media-sync {--user= : Solo esta persona (id)}';

    protected $description = 'Sincroniza las carpetas conectadas de Mis archivos y la biblioteca común con los servicios';

    public function handle(MediaLibrary $media, CommonLibrary $common): int
    {
        if (! config('nas.uploads_enabled')) {
            return self::SUCCESS;
        }
        $failed = 0;
        if ($this->option('user') === null) {
            try {
                $common->sync();
            } catch (Throwable $exception) {
                $failed++;
                $this->warn('Biblioteca común: '.$exception->getMessage());
            }
        }
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
