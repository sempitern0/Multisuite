<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageSetting extends Model
{
    public const SINGLETON = 1;

    public $incrementing = false;

    public static function defaultUserQuotaBytes(): int
    {
        $value = static::query()->whereKey(self::SINGLETON)->value('default_user_quota_bytes');

        return is_numeric($value) ? (int) $value : 0;
    }
}
