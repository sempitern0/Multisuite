<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $connection
 */
class Folder extends Model
{
    /** A fixed top-level folder linked to a service: it cannot be renamed or deleted. */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /** @return BelongsTo<Folder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }
}
