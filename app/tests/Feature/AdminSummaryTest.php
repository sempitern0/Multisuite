<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\Bytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The home page summary shows real counts, and only to the people who manage Multisuite. */
class AdminSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_see_real_counts_and_members_see_nothing(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);
        $member = User::factory()->create(['role' => Role::Member->value]);
        User::factory()->create(['role' => Role::Member->value, 'suspended_at' => now()]);
        $account = new ServiceAccount;
        $account->user_id = $member->id;
        $account->service = 'music';
        $account->remote_id = (string) Str::uuid();
        $account->username = $member->email;
        $account->status = 'conflict';
        $account->save();
        DB::table('user_storage_quotas')->insert([
            ['user_id' => $owner->id, 'limit_bytes' => 10 * 1024 ** 3, 'used_bytes' => 1024 ** 3, 'reserved_bytes' => 0],
            ['user_id' => $member->id, 'limit_bytes' => 10 * 1024 ** 3, 'used_bytes' => 1024 ** 3, 'reserved_bytes' => 0],
        ]);

        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertDontSee('Resumen de administración');

        $page = $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Resumen de administración')
            ->assertSeeInOrder(['Personas', '2', '1 suspendidas'])
            ->assertSeeInOrder(['Cuentas de servicio con incidencias', '1'])
            ->assertSee('Propuestas para todos')
            ->assertSee(route('storage.index'), false);
        $page->assertSeeInOrder(['Espacio usado por todas las personas', Bytes::format(2 * 1024 ** 3)]);
    }
}
