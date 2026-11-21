<?php

namespace App\Models;

use App\Support\Finance\Categories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One income or expense of one person (docs/PERSONAL_FINANCES.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $entry_key
 * @property string $kind
 * @property string $occurred_on Y-m-d (kept as text so every database compares it the same way)
 * @property string $description
 * @property string $category
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $receipt_id
 */
class PersonalTransaction extends Model
{
    protected $fillable = ['kind', 'occurred_on', 'description', 'category', 'amount_minor', 'currency'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'receipt_id' => 'integer'];
    }

    /** @return BelongsTo<UserFile, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(UserFile::class, 'receipt_id');
    }

    /**
     * Every read starts here: a person only ever sees their own movements.
     *
     * @param  Builder<PersonalTransaction>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    public function categoryLabel(): string
    {
        return Categories::label($this->kind, $this->category);
    }
}
