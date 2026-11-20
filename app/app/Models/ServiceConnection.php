<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $service
 * @property string $secret
 * @property string $operator_id
 */
class ServiceConnection extends Model
{
    protected $primaryKey = 'service';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['secret' => 'encrypted'];
    }
}
