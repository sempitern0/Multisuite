<?php

namespace App\Services\Assistant;

use App\Models\AssistantMember;
use App\Models\AssistantSetting;
use App\Models\AssistantToolCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may use the assistant right now and how much (docs/MCP.md § Autorización).
 * Every tool call asks here first, whether it comes from an MCP client or from the chat;
 * domain policies still apply afterwards inside each tool.
 */
class AssistantAccess
{
    /** @var array<string, string> Tool name => what it lets the assistant see, for the owner's settings page. */
    public const TOOLS = [
        'identity_me' => 'Tu cuenta: nombre, usuario y rol',
        'services_list' => 'Servicios que puede abrir cada persona y su estado',
        'storage_my_usage' => 'Espacio usado y disponible de cada persona',
        'files_list' => 'Carpetas y archivos de Mis archivos (nombres, tipos y tamaños; nunca el contenido)',
        'files_search' => 'Buscar en Mis archivos por nombre',
    ];

    /** @var array<string, string> */
    public const DENIALS = [
        'guest' => 'Necesitas iniciar sesión en Multisuite.',
        'disabled' => 'El asistente está desactivado.',
        'unverified' => 'Verifica tu correo antes de usar el asistente.',
        'suspended' => 'Esta cuenta está suspendida.',
        'password' => 'Cambia tu contraseña inicial antes de usar el asistente.',
        'member' => 'No tienes acceso al asistente. Pídeselo a quien administra Multisuite.',
        'tool' => 'Esta herramienta del asistente está desactivada.',
        'budget' => 'Has llegado al límite diario de consultas del asistente. Vuelve a intentarlo mañana.',
    ];

    /** Reason code from DENIALS, or null when the person may use the assistant. */
    public function denial(?User $user): ?string
    {
        $settings = AssistantSetting::current();

        return match (true) {
            $user === null => 'guest',
            ! $settings->enabled => 'disabled',
            ! $user->hasVerifiedEmail() => 'unverified',
            $user->isSuspended() => 'suspended',
            $user->must_change_password => 'password',
            ! $this->isMember($user) => 'member',
            default => null,
        };
    }

    public function isMember(User $user): bool
    {
        return $user->role === 'owner' || AssistantMember::whereKey($user->id)->exists();
    }

    public function toolEnabled(string $tool): bool
    {
        $enabled = AssistantSetting::current()->tools;

        return array_key_exists($tool, self::TOOLS) && ($enabled === null || in_array($tool, $enabled, true));
    }

    public function usedToday(User $user): int
    {
        return AssistantToolCall::where('user_id', $user->id)->whereIn('outcome', AssistantToolCall::CONSUMED)
            ->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Reserves one call of today's budget under a lock on the person, so parallel calls
     * cannot overspend it. Null when the budget is used up (recorded as such).
     */
    public function begin(User $user, string $tool): ?AssistantToolCall
    {
        return DB::transaction(function () use ($user, $tool): ?AssistantToolCall {
            User::whereKey($user->id)->lockForUpdate()->first();
            $exhausted = $this->usedToday($user) >= AssistantSetting::current()->daily_calls;
            $call = $this->record($user, $tool, $exhausted ? 'budget' : 'running');

            return $exhausted ? null : $call;
        });
    }

    public function finish(AssistantToolCall $call, bool $failed, int $startedAt): void
    {
        $call->outcome = $failed ? 'error' : 'ok';
        $call->duration_ms = (int) min(4294967295, intdiv(hrtime(true) - $startedAt, 1000000));
        $call->save();
    }

    public function record(User $user, string $tool, string $outcome): AssistantToolCall
    {
        $call = new AssistantToolCall;
        $call->user_id = $user->id;
        $call->tool = mb_substr($tool, 0, 64);
        $call->outcome = $outcome;
        $call->save();

        return $call;
    }
}
