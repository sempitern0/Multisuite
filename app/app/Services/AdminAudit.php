<?php

namespace App\Services;

use App\Models\AdminAuditEvent;
use App\Models\User;

class AdminAudit
{
    /** @param list<string> $changedFields */
    public function record(User $actor, User $subject, string $action, array $changedFields = [], ?string $previousRole = null): void
    {
        // Explicit allowlist: never serialize request data, user attributes or credentials.
        $event = new AdminAuditEvent;
        $event->actor_id = $actor->id;
        $event->actor_name = $actor->name;
        $event->subject_id = $subject->id;
        $event->subject_name = $subject->name;
        $event->action = $action;
        $event->details = [
            'fields' => array_values(array_intersect($changedFields, ['name', 'email', 'role'])),
            'role' => $subject->role,
            'previous_role' => $previousRole,
        ];
        $event->created_at = now();
        $event->save();
    }
}
