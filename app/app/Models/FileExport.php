<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A ZIP built from a snapshot of a person's files. States: queued → building → ready
 * → cleaned, or failed (→ cleaned once its temporary space is released).
 *
 * @property string $id
 * @property int $user_id
 * @property string $state
 * @property string $name
 * @property string $location_id
 * @property string $root_hash
 * @property list<array{upload: string, path: string}> $items
 * @property int $item_count
 * @property int $estimated_bytes
 * @property int|null $bytes
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $updated_at
 */
class FileExport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['items' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
