<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property int $limit_bytes
 * @property int $used_bytes
 * @property int $reserved_bytes
 */
class UserStorageQuota extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * What a person sees about their space: people without a row of their own
     * follow the default quota until they first add content.
     *
     * @return array{used: int, limit: int, followsDefault: bool}
     */
    public static function summaryFor(int $userId): array
    {
        $quota = self::find($userId);

        return [
            'used' => (int) ($quota->used_bytes ?? 0),
            'limit' => (int) ($quota->limit_bytes ?? StorageSetting::defaultUserQuotaBytes()),
            'followsDefault' => $quota === null,
        ];
    }
}
