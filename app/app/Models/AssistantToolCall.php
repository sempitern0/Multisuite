<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $tool
 * @property string $outcome running|ok|error|denied|budget
 * @property int $duration_ms
 */
class AssistantToolCall extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Outcomes that count against the daily budget. */
    public const CONSUMED = ['running', 'ok', 'error'];

    protected function casts(): array
    {
        return ['duration_ms' => 'integer'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }
}
