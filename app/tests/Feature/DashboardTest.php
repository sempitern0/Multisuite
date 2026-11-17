<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_komga_pilot_is_visible_and_accessible_only_to_administrators(): void
    {
        config(['portal.services.komga.enabled' => true, 'portal.services.komga.url' => 'http://127.0.0.1:25600']);
        $user = User::factory()->create(['role' => 'member']);
        $this->actingAs($user)->get('/services/komga')->assertForbidden();
        $this->assertNotContains('komga', array_column(\App\Support\ServiceTiles::forUser($user), 'id'));
        $user->role = 'admin';
        $user->save();
        $this->get('/services/komga')->assertRedirect('http://127.0.0.1:25600');
        $this->assertContains('komga', array_column(\App\Support\ServiceTiles::forUser($user), 'id'));
        config(['portal.services.komga.enabled' => false]);
        $this->get('/services/komga')->assertNotFound();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_managed_music_can_only_be_opened_after_provisioning(): void
    {
        config(['portal.services.music.enabled' => true, 'portal.services.music.url' => 'https://music.example.test', 'provisioning.music.enabled' => true]);
        $user = User::factory()->create();
        $user->service_access = ['music'];
        $user->save();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Abrir Música');
        $account = new ServiceAccount;
        $account->user_id = $user->id;
        $account->service = 'music';
        $account->remote_id = (string) Str::uuid();
        $account->username = $user->email;
        foreach (['pending', 'configuration', 'conflict', 'expired'] as $status) {
            $account->status = $status;
            $account->save();
            $this->get(route('dashboard'))->assertOk()->assertSee('Cuenta pendiente de preparación')->assertDontSee('Abrir Música');
            $this->get('/services/music')->assertRedirect(route('dashboard'));
        }
        $account->status = 'ready';
        $account->save();
        $this->get(route('dashboard'))->assertOk()->assertSee('Abrir Música');
        $this->get('/services/music')->assertRedirect('https://music.example.test');
    }
}
