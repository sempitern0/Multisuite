<?php

namespace App\Console\Commands;

use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Models\User;
use App\Services\Storage\PrivateUploads;
use Illuminate\Console\Command;
use Throwable;

class CleanupUploads extends Command
{
    protected $signature = 'portal:uploads-cleanup';

    protected $description = 'Elimina subidas privadas pendientes caducadas antes de liberar su reserva';

    public function handle(PrivateUploads $uploads): int
    {
        $failed = false;
        // Only expired reservations that still belong to a pending upload (one query per chunk, not per row).
        $expired = StorageReservation::where('state', 'reserved')->where('expires_at', '<', now())
            ->whereIn('id', PrivateUpload::select('id')->where('state', 'pending'));
        foreach ($expired->lazyById(100) as $reservation) {
            try {
                $uploads->cancel(User::findOrFail($reservation->user_id), $reservation->id);
            } catch (Throwable) {
                $failed = true;
                $this->error('No se pudo limpiar '.$reservation->id.'. Reserva conservada; revisar ubicación y contabilidad.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
