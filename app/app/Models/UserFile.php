<?php

namespace App\Models;

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
 */
class UserFile extends Model
{
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
