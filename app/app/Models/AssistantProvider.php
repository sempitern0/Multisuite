<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An external AI provider the owner added (docs/MCP.md § Proveedores). The key is written,
 * never read back to the browser: it is hidden from serialisation and encrypted at rest.
 *
 * @property int $id
 * @property string $driver
 * @property string $model
 * @property string $api_key
 * @property string $key_hint
 * @property int $position
 * @property bool $enabled
 */
class AssistantProvider extends Model
{
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'position' => 'integer', 'enabled' => 'boolean'];
    }

    /** Name under which laravel/ai builds this provider; never a built-in provider name. */
    public function runtimeName(): string
    {
        return 'multisuite-provider-'.$this->id;
    }
}
