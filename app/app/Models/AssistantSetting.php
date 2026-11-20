<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $enabled
 * @property list<string>|null $tools Enabled tool names; null means every tool.
 * @property int $daily_calls
 * @property int $daily_tokens
 */
class AssistantSetting extends Model
{
    public const SINGLETON = 1;

    public $incrementing = false;

    protected $attributes = ['enabled' => false, 'daily_calls' => 200, 'daily_tokens' => 200000];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'tools' => 'array', 'daily_calls' => 'integer', 'daily_tokens' => 'integer'];
    }

    public static function current(): self
    {
        $settings = static::query()->find(self::SINGLETON);
        if ($settings === null) {
            $settings = new self;
            $settings->id = self::SINGLETON;
        }

        return $settings;
    }
}
