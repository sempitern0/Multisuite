<?php

namespace App\Models;

use App\Services\ServiceAccounts;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $service
 * @property string $remote_id
 * @property string|null $external_id
 * @property string|null $remote_email
 * @property string|null $media_id
 * @property array<string, string>|null $library_ids
 * @property string $username
 * @property string $status
 * @property string|null $pending_password
 * @property CarbonInterface|null $credential_expires_at
 * @property CarbonInterface|null $retry_at
 */
class ServiceAccount extends Model
{
    protected $hidden = ['pending_password'];

    /** @return array{label: string, tone: 'ok'|'warn'|'bad'|'muted'} human state for the interface (single source) */
    public function state(): array
    {
        return match ($this->status) {
            'ready' => ['label' => 'Activa', 'tone' => 'ok'],
            ServiceAccounts::LOCK_PENDING => ['label' => 'Bloqueo remoto pendiente', 'tone' => 'warn'],
            ServiceAccounts::LOCKED => ['label' => 'Desactivada', 'tone' => 'muted'],
            ServiceAccounts::AWAITING_PASSWORD => ['label' => 'Se activará en su próximo acceso con contraseña', 'tone' => 'warn'],
            'expired' => ['label' => 'Caducada: se reintentará en su próximo acceso', 'tone' => 'warn'],
            'conflict' => ['label' => 'Ese usuario ya existe en el servicio', 'tone' => 'bad'],
            'configuration' => ['label' => 'El servicio no está configurado', 'tone' => 'bad'],
            default => ['label' => 'Preparándose', 'tone' => 'warn'],
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_password' => 'encrypted',
            'library_ids' => 'array',
            'credential_expires_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
        ];
    }
}
