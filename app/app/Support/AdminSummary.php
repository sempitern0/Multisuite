<?php

namespace App\Support;

use App\Enums\PublicationState;
use App\Enums\ServiceAccountStatus;
use App\Models\Invitation;
use App\Models\Publication;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Downloads\StagedDownloads;
use Illuminate\Support\Facades\Gate;

/**
 * Administration summary on the home page: only real counts from the database, and only the
 * figures and links the person's gates allow (docs/USER_MANAGEMENT.md § Resumen).
 */
final class AdminSummary
{
    /** Service account states that need someone to act (configuration or a remote conflict). */
    private const NEEDS_ATTENTION = [ServiceAccountStatus::Conflict, ServiceAccountStatus::Configuration];

    /**
     * @return array{metrics: list<array{label: string, value: string, tone: string|null, sub: string|null}>, links: list<array{label: string, href: string}>}|null
     */
    public static function for(User $user): ?array
    {
        $metrics = [];
        $links = [];
        $gate = Gate::forUser($user);

        if ($gate->allows('manage-invitations')) {
            $active = User::whereNull('suspended_at')->count();
            $suspended = User::whereNotNull('suspended_at')->count();
            $metrics[] = self::metric('Personas', (string) $active, $suspended > 0 ? $suspended.' suspendidas' : 'Ninguna suspendida');
            $pending = Invitation::query()->inStatus('pending')->count();
            $metrics[] = self::metric('Invitaciones pendientes', (string) $pending);
            $attention = ServiceAccount::whereIn('status', array_map(fn (ServiceAccountStatus $s): string => $s->value, self::NEEDS_ATTENTION))->count();
            $metrics[] = self::metric('Cuentas de servicio con incidencias', (string) $attention, $attention > 0 ? 'Revísalas en Personas' : null, $attention > 0 ? 'bad' : null);
            $links[] = ['label' => 'Personas', 'href' => route('users.index')];
            $links[] = ['label' => 'Invitaciones', 'href' => route('invitations.index')];
        }
        if ($gate->allows('publish-content')) {
            $proposals = Publication::where('state', PublicationState::Requested->value)->count();
            $metrics[] = self::metric('Propuestas para todos', (string) $proposals, $proposals > 0 ? 'Esperan tu decisión' : null, $proposals > 0 ? 'accent' : null);
            $links[] = ['label' => 'Para todos', 'href' => route('publications.index')];
        }
        if ($gate->allows('manage-downloads')) {
            $waiting = app(StagedDownloads::class)->count();
            $metrics[] = self::metric('Descargas por guardar', (string) $waiting, $waiting > 0 ? 'Guárdalas en Mis archivos o descártalas' : null);
            $links[] = ['label' => 'Descargas por guardar', 'href' => route('downloads.index')];
        }
        if ($gate->allows('view-storage')) {
            $used = (int) UserStorageQuota::sum('used_bytes');
            $metrics[] = self::metric('Espacio usado por todas las personas', Bytes::format($used));
            $links[] = ['label' => 'Almacenamiento', 'href' => route('storage.index')];
        }

        return $metrics === [] ? null : ['metrics' => $metrics, 'links' => $links];
    }

    /** @return array{label: string, value: string, tone: string|null, sub: string|null} */
    private static function metric(string $label, string $value, ?string $sub = null, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone, 'sub' => $sub];
    }
}
