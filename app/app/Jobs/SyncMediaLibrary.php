<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Files\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMediaLibrary implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId) {}

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MediaLibrary $media): void
    {
        $user = User::find($this->userId);
        if ($user !== null) {
            $media->sync($user);
        }
    }
}
