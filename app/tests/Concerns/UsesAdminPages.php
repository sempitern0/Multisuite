<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Drives the administration pages (the only entry to these operations) as the signed-in
 * person. When the page itself refuses to open, the refused page is returned so the test
 * can assert it; otherwise the fields are filled and the action is called.
 */
trait UsesAdminPages
{
    /** @param array<string, mixed> $input */
    protected function updatePerson(User $target, array $input)
    {
        $target = User::findOrFail($target->id); // as route binding does: never a stale instance
        $page = Livewire::test('pages::admin.user-edit', ['user' => $target]);
        if (Gate::denies('update', $target)) {
            return $page;
        }
        foreach ($input as $field => $value) {
            $page->set($field, $field === 'email_confirmed' ? (bool) $value : $value);
        }

        return $page->call('save');
    }

    protected function deletePerson(User $target, string $confirmEmail = '')
    {
        $target = User::findOrFail($target->id);
        $page = Livewire::test('pages::admin.user-edit', ['user' => $target]);

        return Gate::denies('delete', $target) ? $page : $page->set('confirm_email', $confirmEmail)->call('delete');
    }

    /** @param array<string, mixed> $input */
    protected function createPerson(array $input)
    {
        return $this->fillAndCall('pages::admin.user-create', 'manage-invitations', $input + ['services' => []], 'create');
    }

    /** @param array<string, mixed> $input */
    protected function invite(array $input)
    {
        return $this->fillAndCall('pages::admin.invitations', 'manage-invitations', $input + ['services' => []], 'invite');
    }

    protected function revokeInvitation(int $invitationId)
    {
        return $this->fillAndCall('pages::admin.invitations', 'manage-invitations', [], 'revoke', $invitationId);
    }

    /** @param array<string, mixed> $size preset, amount, unit */
    protected function saveQuota(User $target, array $size)
    {
        return $this->fillAndCall('pages::admin.storage', 'view-storage', [], 'saveUser', $target->id, $size);
    }

    /** @param array<string, mixed> $size */
    protected function savePool(string $pool, array $size)
    {
        return $this->fillAndCall('pages::admin.storage', 'view-storage', [], 'savePool', $pool, $size);
    }

    /** @param array<string, mixed> $size */
    protected function saveDefault(array $size)
    {
        return $this->fillAndCall('pages::admin.storage', 'view-storage', [], 'saveDefault', $size);
    }

    /** @param array<string, mixed> $fields */
    private function fillAndCall(string $page, string $gate, array $fields, string $action, mixed ...$arguments)
    {
        $component = Livewire::test($page);
        if (Gate::denies($gate)) {
            return $component;
        }
        foreach ($fields as $field => $value) {
            $component->set($field, $value);
        }

        return $component->call($action, ...$arguments);
    }
}
