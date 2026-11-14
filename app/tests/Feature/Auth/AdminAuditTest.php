<?php

namespace Tests\Feature\Auth;

use App\Enums\ServiceAuditAction;
use App\Models\AdminAuditEvent;
use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
        $this->get('/admin/activity?action=user.deleted')->assertOk()->assertSee('Usuario eliminado');
        $rows = fn (string $action): int => substr_count(
            Livewire::withQueryParams(['action' => $action])->test('pages::admin.activity')->html(),
            'wire:key="event-',
        );
        $this->assertSame(1, $rows('user.deleted'));
        // Unknown filter values coming from the URL are ignored instead of failing.
        $this->assertSame(2, $rows('invalid'));
        $this->get('/admin/activity?action=invalid')->assertOk();
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

    public function test_service_contract_supports_background_actors_and_historical_filters(): void
    {
        $actor = $this->administrator();
        $musicUser = User::factory()->create(['name' => 'Music subject']);
        $reader = User::factory()->create(['name' => 'Reader subject']);
        config(['portal.services.reading' => ['name' => 'Lectura', 'enabled' => false]]);
        $audit = app(AdminAudit::class);
        $audit->recordService($actor, $musicUser, 'music', ServiceAuditAction::AccessGranted);
        $audit->recordService(null, $reader, 'reading', ServiceAuditAction::SyncSucceeded);
        $event = AdminAuditEvent::where('service_id', 'reading')->firstOrFail();
        $this->assertNull($event->actor_id);
        $this->assertSame('Sistema', $event->actor_name);
        $this->assertSame([], $event->details);
        $this->assertSame(1, $event->schema_version);
        // Removing a service from configuration must not hide its historical events.
        config(['portal.services.reading' => null]);
        $this->get('/admin/activity?service=reading&action=service.sync_succeeded')
            ->assertOk()->assertSee('Reader subject')->assertDontSee('Music subject');
        $this->assertDatabaseCount('admin_audit_events', 2);
    }

    public function test_unknown_services_are_rejected_without_an_event(): void
    {
        $actor = $this->administrator();
        try {
            app(AdminAudit::class)->recordService($actor, $actor, 'unknown', ServiceAuditAction::AccountCreated);
            $this->fail('An unregistered service must be rejected.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('admin_audit_events', 0);
        }
    }
}
