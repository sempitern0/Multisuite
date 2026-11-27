<?php

namespace App\Models;

use App\Enums\PublicationState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file proposed or published for everyone (docs/STORAGE.md § Publicación para todos).
 *
 * @property int $id
 * @property string $upload_id
 * @property string $connection
 * @property string $title
 * @property int $bytes
 * @property int|null $contributor_id
 * @property int|null $user_file_id
 * @property string $state
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $withdrawal_requested_at
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 */
class Publication extends Model
{
    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'withdrawal_requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'bytes' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributor_id');
    }

    /** @return BelongsTo<PrivateUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PrivateUpload::class, 'upload_id');
    }

    public function isPublished(): bool
    {
        return $this->state === PublicationState::Published->value;
    }
}
