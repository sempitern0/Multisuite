<?php

namespace Tests\Feature\Auth;

use App\Models\AdminAuditEvent;
use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $actor = User::factory()->create();
        $actor->role = 'owner';
        $actor->save();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);

        return $actor;
    }

    public function test_history_is_private_and_requires_password_confirmation(): void
    {
        $this->get('/admin/activity')->assertRedirect('/login');
        $member = User::factory()->create();
        $this->actingAs($member)->get('/admin/activity')->assertForbidden();
        $this->get('/dashboard')->assertDontSee('href="'.route('audit.index').'"', false);
        $member->role = 'admin';
        $member->save();
        $this->get('/admin/activity')->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])->get('/admin/activity')->assertOk();
    }

    public function test_manual_creation_records_identity_without_credentials(): void
    {
        $actor = $this->administrator();
        $this->post('/users', [
            'name' => 'Audit subject', 'email' => 'audit@example.com', 'role' => 'member',
            'password' => 'Temporary-Password-123!', 'password_confirmation' => 'Temporary-Password-123!',
        ])->assertRedirect();
        $event = AdminAuditEvent::firstOrFail();
        $this->assertSame('user.created', $event->action);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame(User::where('email', 'audit@example.com')->firstOrFail()->id, $event->subject_id);
        $this->assertStringNotContainsString('Temporary-Password', $event->toJson());
        $this->assertStringNotContainsString('audit@example.com', $event->toJson());
    }

    public function test_update_and_deletion_history_survives_removal_and_can_be_filtered(): void
    {
        $this->administrator();
        $member = User::factory()->create();
        $this->put('/users/'.$member->id, ['name' => 'Changed', 'email' => $member->email, 'role' => 'admin'])->assertRedirect();
        $event = AdminAuditEvent::firstOrFail();
        $this->assertSame(['name', 'role'], $event->details['fields']);
        $this->assertSame('member', $event->details['previous_role']);
        $this->assertSame('admin', $event->details['role']);
        $this->delete('/users/'.$member->id, ['confirm_email' => $member->email])->assertRedirect();
        $this->assertModelMissing($member);
        $this->assertDatabaseCount('admin_audit_events', 2);
        $this->get('/admin/activity?action=user.deleted')->assertOk()->assertSee('Usuario eliminado')->assertDontSee('Usuario modificado');
        $this->get('/admin/activity?action=invalid')->assertSessionHasErrors('action');
    }

    public function test_rejected_and_unchanged_updates_do_not_record_success(): void
    {
        $actor = $this->administrator();
        $member = User::factory()->create();
        $this->put('/users/'.$member->id, ['name' => $member->name, 'email' => $member->email, 'role' => 'member'])->assertRedirect();
        $this->delete('/users/'.$member->id, ['confirm_email' => 'wrong'])->assertSessionHasErrors();
        $this->delete('/users/'.$actor->id, ['confirm_email' => $actor->email])->assertForbidden();
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_audit_write_failure_rolls_back_local_changes(): void
    {
        $this->administrator();
        $member = User::factory()->create();
        $originalName = $member->name;
        $this->mock(AdminAudit::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->put('/users/'.$member->id, ['name' => 'Must roll back', 'email' => $member->email, 'role' => 'member']);
            $this->fail('Audit failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame($originalName, $member->fresh()->name);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }
}
