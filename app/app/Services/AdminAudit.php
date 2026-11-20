<?php

namespace App\Services;

use App\Enums\ServiceAuditAction;
use App\Models\AdminAuditEvent;
use App\Models\User;
use LogicException;

class AdminAudit
{
    /** @var array<string, string> */
    public const STORAGE_ACTIONS = [
        'storage.user_quota' => 'Espacio de usuario modificado',
        'storage.pool_limit' => 'Límite de disco modificado',
        'storage.default_quota' => 'Espacio por defecto modificado',
    ];

    /** @var array<string, string> */
    public const USER_ACTIONS = [
        'user.created' => 'Usuario creado',
        'user.updated' => 'Usuario modificado',
        'user.deleted' => 'Usuario eliminado',
        'user.username_changed' => 'Nombre de usuario cambiado',
        'user.suspended' => 'Cuenta suspendida',
        'user.reactivated' => 'Cuenta reactivada',
        'user.avatar_removed' => 'Foto de perfil retirada',
    ];

    /** @var array<string, string> */
    public const ASSISTANT_ACTIONS = [
        'assistant.updated' => 'Asistente configurado',
        'assistant.member_added' => 'Acceso al asistente concedido',
        'assistant.member_removed' => 'Acceso al asistente retirado',
        'assistant.provider_saved' => 'Proveedor de IA guardado',
        'assistant.provider_removed' => 'Proveedor de IA eliminado',
    ];

    /** @return array<string, string> */
    public static function actionLabels(): array
    {
        $labels = [...self::USER_ACTIONS, ...self::STORAGE_ACTIONS, ...self::ASSISTANT_ACTIONS];
        foreach (ServiceAuditAction::cases() as $action) {
            $labels[$action->value] = $action->label();
        }

        return $labels;
    }

    public function recordService(?User $actor, User $subject, string $service, ServiceAuditAction $action): void
    {
        $actorId = $actor?->id;
        $subjectId = $subject->id;
        if (($actorId !== null && $actorId < 1) || $subjectId < 1) {
            throw new LogicException('Audit requires persisted user identifiers.');
        }
        if (! preg_match('/^[a-z0-9-]{1,64}$/D', $service) || ! array_key_exists($service, config('portal.services', []))) {
            throw new LogicException('Audit requires a registered service identifier.');
        }

        $event = new AdminAuditEvent;
        $event->actor_id = $actorId;
        $event->actor_name = $actor->name ?? 'Sistema';
        $event->subject_id = $subjectId;
        $event->subject_name = $subject->name;
        $event->service_id = $service;
        $event->schema_version = 1;
        $event->action = $action->value;
        // No arbitrary payloads: adapters must never pass API responses or exceptions.
        $event->details = [];
        $event->created_at = now();
        $event->save();
    }

    /**
     * Storage policy changes. Pool and default changes have no affected person, so the
     * actor is also recorded as subject. Only sizes and the pool identifier are stored.
     */
    public function recordStorage(User $actor, User $subject, string $action, ?string $pool, int $limitBytes, ?int $previousBytes): void
    {
        if (! array_key_exists($action, self::STORAGE_ACTIONS) || ($pool !== null && ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $pool))) {
            throw new LogicException('Unknown storage audit action.');
        }
        if ($actor->id < 1 || $subject->id < 1) {
            throw new LogicException('Audit requires persisted user identifiers.');
        }

        $event = new AdminAuditEvent;
        $event->actor_id = $actor->id;
        $event->actor_name = $actor->name;
        $event->subject_id = $subject->id;
        $event->subject_name = $subject->name;
        $event->action = $action;
        $event->details = ['pool' => $pool, 'limit_bytes' => $limitBytes, 'previous_limit_bytes' => $previousBytes];
        $event->created_at = now();
        $event->save();
    }

    /** @param list<string> $changedFields */
    public function record(User $actor, User $subject, string $action, array $changedFields = [], ?string $previousRole = null): void
    {
        if (! array_key_exists($action, self::USER_ACTIONS)) {
            throw new LogicException('Unknown user audit action.');
        }
        $actorId = $actor->id;
        $subjectId = $subject->id;
        if ($actorId < 1 || $subjectId < 1) {
            throw new LogicException('Audit requires persisted user identifiers.');
        }

        // Explicit allowlist: never serialize request data, user attributes or credentials.
        $event = new AdminAuditEvent;
        $event->actor_id = $actorId;
        $event->actor_name = $actor->name;
        $event->subject_id = $subjectId;
        $event->subject_name = $subject->name;
        $event->action = $action;
        $event->details = [
            'fields' => array_values(array_intersect($changedFields, ['name', 'email', 'role', 'username'])),
            'role' => $subject->role,
            'previous_role' => $previousRole,
        ];
        $event->created_at = now();
        $event->save();
    }

    /** Assistant configuration (docs/MCP.md). Settings changes record the owner as subject; no details are stored. */
    public function recordAssistant(User $actor, User $subject, string $action): void
    {
        if (! array_key_exists($action, self::ASSISTANT_ACTIONS)) {
            throw new LogicException('Unknown assistant audit action.');
        }
        if ($actor->id < 1 || $subject->id < 1) {
            throw new LogicException('Audit requires persisted user identifiers.');
        }

        $event = new AdminAuditEvent;
        $event->actor_id = $actor->id;
        $event->actor_name = $actor->name;
        $event->subject_id = $subject->id;
        $event->subject_name = $subject->name;
        $event->action = $action;
        $event->details = [];
        $event->created_at = now();
        $event->save();
    }
}
