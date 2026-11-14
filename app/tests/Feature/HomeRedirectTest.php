<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "/" has no landing page: it sends people where they are going anyway. */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_go_straight_to_sign_in(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }

    public function test_signed_in_people_go_to_their_start_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
        $this->actingAs(User::factory()->create(['preferences' => ['start' => 'files']]))->get(route('home'))->assertRedirect(route('files.index'));
    }
}
