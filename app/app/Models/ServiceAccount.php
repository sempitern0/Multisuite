<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $service
 * @property string $remote_id
 * @property string $username
 * @property string $status
 * @property string|null $pending_password
 * @property CarbonInterface|null $credential_expires_at
 * @property CarbonInterface|null $retry_at
 */
class ServiceAccount extends Model
{
    protected $hidden = ['pending_password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_password' => 'encrypted',
            'credential_expires_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
        ];
    }
}
