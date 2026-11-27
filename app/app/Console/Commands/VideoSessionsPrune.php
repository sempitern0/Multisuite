<?php

namespace App\Console\Commands;

use App\Services\JellyfinClient;
use App\Services\JellyfinServer;
use Illuminate\Console\Command;
use RuntimeException;

/** Retires Vídeo browser sessions idle for 30 days (docs/JELLYFIN.md § Entrada sin segundo inicio de sesión). */
class VideoSessionsPrune extends Command
{
    protected $signature = 'portal:video-sessions-prune {--days=30 : Días sin actividad}';

    protected $description = 'Retira de Jellyfin las sesiones de navegador abiertas por el portal que llevan tiempo sin usarse';

    public function handle(JellyfinServer $jellyfin): int
    {
        if (! config('provisioning.video.enabled') || ! JellyfinClient::connected()) {
            return self::SUCCESS;
        }
        try {
            $this->info('Sesiones retiradas: '.$jellyfin->pruneBrowserSessions(max(1, (int) $this->option('days'))));
        } catch (RuntimeException $exception) {
            $this->warn('Vídeo no disponible: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
