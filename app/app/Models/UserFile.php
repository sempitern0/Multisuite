<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visible entry of a private upload. Bytes, MIME and state live in the upload and
 * its reservation; this row only adds ownership, folder and display name.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $folder_id
 * @property string $upload_id
 * @property string $name
 * @property int|null $original_folder_id
 * @property int|null $version_of_id
 * @property CarbonImmutable|null $trashed_at
 * @property CarbonImmutable|null $superseded_at
 * @property-read string|null $mime Joined from private_uploads in listing queries only.
 * @property-read int|string|null $size Joined verified bytes in listing queries only.
 */
class UserFile extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['trashed_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    public function isCurrent(): bool
    {
        return $this->trashed_at === null && $this->version_of_id === null;
    }

    /** @return BelongsTo<PrivateUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PrivateUpload::class, 'upload_id');
    }

    /** @return BelongsTo<StorageReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StorageReservation::class, 'upload_id');
    }
}
