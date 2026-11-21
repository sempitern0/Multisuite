<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $enabled
 * @property list<string>|null $tools Enabled tool names; null means every tool.
 * @property int $daily_calls
 * @property int $daily_tokens
 * @property bool $content_allowed Whether people may let the assistant read their files (D3).
 * @property bool $semantic_enabled Search by meaning over the files of people who accepted it (MCP-07).
 * @property bool $external_enabled Whether external MCP clients may connect (MCP-05).
 */
class AssistantSetting extends Model
{
    public const SINGLETON = 1;

    public $incrementing = false;

    protected $attributes = ['enabled' => false, 'daily_calls' => 200, 'daily_tokens' => 200000, 'content_allowed' => false, 'semantic_enabled' => false, 'external_enabled' => false];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'tools' => 'array', 'daily_calls' => 'integer', 'daily_tokens' => 'integer', 'content_allowed' => 'boolean', 'semantic_enabled' => 'boolean', 'external_enabled' => 'boolean'];
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
