<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_routes_and_links_are_removed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Attacker', 'email' => 'attacker@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'role' => 'owner',
        ])->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('href="http://localhost/register"', false);
        $this->assertDatabaseCount('users', 0);
    }
}
