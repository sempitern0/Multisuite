<?php

namespace App\Actions\Assistant;

use App\Models\AssistantProvider;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Assistant\AssistantProviders;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes an external AI provider (owner only, decision D2). The key is optional when
 * editing: leaving it empty keeps the stored one. Only its last 4 characters are kept readable.
 */
class SaveAssistantProvider
{
    public function __construct(private AdminAudit $audit) {}

    public function handle(User $actor, ?int $providerId, string $driver, string $model, ?string $key, int $position, bool $enabled): AssistantProvider
    {
        $model = trim($model);
        $key = $key === null ? null : trim($key);
        $errors = [];
        if (! array_key_exists($driver, AssistantProviders::DRIVERS)) {
            $errors['driver'] = 'Elige un proveedor de la lista.';
        }
        if (! preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/D', $model)) {
            $errors['model'] = 'Escribe el nombre del modelo tal como lo da el proveedor (letras, números, puntos, guiones o barras).';
        }
        if (($providerId === null && ($key === null || $key === '')) || ($key !== null && $key !== '' && (mb_strlen($key) < 16 || mb_strlen($key) > 512 || preg_match('/\s/', $key)))) {
            $errors['key'] = 'Pega la clave completa del proveedor, sin espacios.';
        }
        if ($position < 1 || $position > 99) {
            $errors['position'] = 'Elige un orden entre 1 y 99.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $providerId, $driver, $model, $key, $position, $enabled): AssistantProvider {
            [$actor] = LockedUsers::pair($actor->id, $actor->id);
            Gate::forUser($actor)->authorize('manage-assistant');
            $provider = $providerId === null ? new AssistantProvider : AssistantProvider::lockForUpdate()->findOrFail($providerId);
            $provider->driver = $driver;
            $provider->model = $model;
            $provider->position = $position;
            $provider->enabled = $enabled;
            if ($key !== null && $key !== '') {
                $provider->api_key = $key;
                $provider->key_hint = mb_substr($key, -4);
            }
            if ($provider->isDirty()) {
                $provider->save();
                $this->audit->recordAssistant($actor, $actor, 'assistant.provider_saved');
            }

            return $provider;
        });
    }
}
