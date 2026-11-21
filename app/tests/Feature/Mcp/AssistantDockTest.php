<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assistant\SaveAssistantProvider;
use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Ai\Agents\MultisuiteAssistant;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Floating assistant: a small button on every page for people who can use the assistant,
 * hideable per person, continuing the same conversation between pages.
 */
class AssistantDockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->member = User::factory()->create(['role' => 'member']);
        app(UpdateAssistantSettings::class)->handle($this->owner, true, array_keys(AssistantAccess::TOOLS), 200, 200000);
    }

    public function test_the_button_appears_for_people_with_access_except_on_the_assistant_page(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('Abrir el asistente');
        $this->actingAs($this->owner)->get('/assistant')->assertOk()->assertDontSee('Abrir el asistente');
        $this->actingAs($this->member)->get('/dashboard')->assertOk()->assertDontSee('Abrir el asistente');

        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        $this->actingAs($this->member)->get('/dashboard')->assertOk()->assertSee('Abrir el asistente');

        app(UpdateAssistantSettings::class)->handle($this->owner, false, array_keys(AssistantAccess::TOOLS), 200, 200000);
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertDontSee('Abrir el asistente');
    }

    public function test_each_person_can_hide_it_and_bring_it_back_from_preferences(): void
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::assistant-dock')->call('hide')->assertDontSee('Abrir el asistente');
        $this->assertFalse($this->owner->fresh()?->preferences()->assistantButton());
        $this->get('/dashboard')->assertOk()->assertDontSee('Abrir el asistente');

        Livewire::test('pages::settings.preferences')->assertSee('Botón del asistente')->set('assistant_button', 'on');
        $this->assertTrue($this->owner->fresh()?->preferences()->assistantButton());

        $this->actingAs($this->member);
        Livewire::test('pages::settings.preferences')->assertDontSee('Botón del asistente');
    }

    public function test_the_panel_opens_on_the_conversation_the_person_was_having(): void
    {
        app(SaveAssistantProvider::class)->handle($this->owner, null, 'anthropic', 'claude-sonnet-5-5', 'sk-test-0123456789abcdefWXYZ', 1, true);
        MultisuiteAssistant::fake(['Te quedan 3 GB libres.']);
        $conversation = app(AssistantChat::class)->send($this->owner, null, '¿Cuánto espacio me queda?')['conversation'];
        $this->actingAs($this->owner)->withSession(['assistant.dock' => $conversation]);

        Livewire::test('pages::assistant-dock')->assertDontSee('Te quedan 3 GB libres.')
            ->call('toggle')->assertSee('Te quedan 3 GB libres.')->assertSee('Tu pregunta')
            ->call('start')->assertDontSee('Te quedan 3 GB libres.');
        $this->assertNull(session('assistant.dock'));

        // Someone else's conversation id in the session shows nothing.
        $this->actingAs($this->member)->withSession(['assistant.dock' => $conversation, 'assistant.dock_open' => true]);
        app(SetAssistantMember::class)->handle($this->owner, $this->member, true);
        Livewire::test('pages::assistant-dock')->assertDontSee('Te quedan 3 GB libres.');
    }

    public function test_answers_never_load_images(): void
    {
        $this->actingAs($this->owner);
        $html = Livewire::test('pages::assistant-dock')->instance()->answer('Mira esto ![x](https://example.com/pixel.png?d=secreto) y **esto**');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<strong>esto</strong>', $html);
    }
}
