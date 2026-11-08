<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_service(): void
    {
        $this->get('/services/music')->assertRedirect('/login');
    }

    public function test_destination_is_only_taken_from_configuration(): void
    {
        config(['portal.services.music' => ['enabled' => true, 'url' => 'http://127.0.0.1:4533']]);
        $this->actingAs(User::factory()->create());
        $this->get('/services/music?url=https://example.org')->assertRedirect('http://127.0.0.1:4533');
        $this->get('/services/unknown')->assertNotFound();
    }

    public function test_disabled_or_unsafe_destinations_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['javascript:alert(1)', '//example.org', 'https://user:secret@example.org', ''] as $url) {
            config(['portal.services.music' => ['enabled' => true, 'url' => $url]]);
            $this->get('/services/music')->assertNotFound();
        }
        config(['portal.services.music' => ['enabled' => false, 'url' => 'https://example.org']]);
        $this->get('/services/music')->assertNotFound();
    }
}
