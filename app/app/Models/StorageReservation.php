<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property \Carbon\CarbonImmutable $expires_at */
class StorageReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
