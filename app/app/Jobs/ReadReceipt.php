<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserFile;
use App\Services\Files\PersonalFiles;
use App\Support\Finance\ReceiptReader;
use App\Support\Finance\ReceiptUnreadable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads one receipt off the web request (OCR can take seconds). The result waits in the cache
 * for the page that asked; nothing is recorded until the person reviews and saves it.
 */
class ReadReceipt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60; // below the queue's retry_after (90)

    public int $uniqueFor = 120;

    public function __construct(public int $userId, public int $fileId) {}

    public static function key(int $userId, int $fileId): string
    {
        return 'receipt-scan:'.$userId.':'.$fileId;
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->fileId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10];
    }

    public function handle(PersonalFiles $files, ReceiptReader $reader): void
    {
        $user = User::find($this->userId);
        $file = $user === null ? null : UserFile::where('user_id', $user->id)->find($this->fileId);
        if ($user === null || $file === null || ! $file->isCurrent()) {
            $this->store(['status' => 'failed', 'error' => 'La factura ya no está en Mis archivos.']);

            return;
        }
        try {
            $source = $files->readable($user, $file);
            $this->store(['status' => 'done'] + $reader->read($source['blob'], $source['mime']));
        } catch (ReceiptUnreadable $exception) {
            // Expected outcomes (unsupported, unreadable, too large): no retry, tell the person why.
            $this->store(['status' => 'failed', 'error' => $exception->getMessage()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->store(['status' => 'failed', 'error' => 'No se ha podido leer la factura. Puedes completar el movimiento a mano.']);
    }

    /** @param array<string, string> $result */
    private function store(array $result): void
    {
        Cache::put(self::key($this->userId, $this->fileId), $result, now()->addMinutes(15));
    }
}
