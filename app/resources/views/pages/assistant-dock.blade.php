<?php

use App\Livewire\Concerns\ChatsWithAssistant;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/*
 * Floating assistant (button + panel) on every page except Asistente itself. Closed it costs
 * no queries; the open conversation is remembered for the session so it follows the person
 * between pages. Each person can hide the button (Preferences brings it back).
 */
new class extends Component {
    use ChatsWithAssistant, NotifiesInterface, ThrottlesActions;

    public bool $open = false;

    public ?string $conversation = null;

    public string $message = '';

    public bool $hidden = false;

    public function boot(): void
    {
        Gate::authorize('use-assistant');
    }

    public function mount(): void
    {
        $remembered = session('assistant.dock');
        $this->conversation = is_string($remembered) ? $remembered : null;
        $this->open = session('assistant.dock_open') === true;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        session(['assistant.dock_open' => $this->open]);
    }

    public function close(): void
    {
        $this->open = false;
        session(['assistant.dock_open' => false]);
    }

    /** Hides the button for this person; Preferences › Botón del asistente shows it again. */
    public function hide(): void
    {
        $user = Auth::user();
        $user->preferences = $user->preferences()->with('assistant_button', 'off')->toArray();
        $user->save();
        session(['assistant.dock_open' => false]);
        $this->hidden = true;
        $this->toast('Botón del asistente oculto. Puedes volver a mostrarlo en Preferencias.');
    }

    protected function conversationChanged(): void
    {
        session(['assistant.dock' => $this->conversation]);
    }
}; ?>

<div>
    @unless ($hidden)
        <x-ui::dock :open="$open" title="Asistente" toggle="toggle" close="close">
            <x-slot:tools>
                <x-ui::button size="sm" variant="ghost" icon="plus" wire:click="start" label="Nueva conversación" />
                <x-ui::button size="sm" variant="ghost" icon="arrow-right" :href="route('assistant.index', $conversation === null ? [] : ['c' => $conversation])" label="Abrir en pantalla completa" />
                <x-ui::button size="sm" variant="ghost" icon="eye-off" wire:click="hide" label="Ocultar este botón" />
            </x-slot:tools>

            @if ($open)
                <div class="ui-chat" aria-live="polite">
                    @if ($this->messages->isEmpty())
                        <p class="ui-help">Pregunta por tus archivos, tu espacio o tus servicios. Solo ve lo tuyo y no cambia nada sin que lo apruebes.</p>
                    @endif
                    @foreach ($this->messages->take(-30) as $index => $turn)
                        @if ($turn->role === 'user')
                            <x-ui::chat-message role="user" author="Tú" wire:key="dock-turn-{{ $index }}">{{ $turn->content }}</x-ui::chat-message>
                        @else
                            <x-ui::chat-message role="assistant" author="Asistente" wire:key="dock-turn-{{ $index }}"><x-ui::prose>{!! $this->answer($turn->content) !!}</x-ui::prose></x-ui::chat-message>
                        @endif
                    @endforeach
                    @if ($this->pending !== [])
                        <x-ui::panel tone="dashed" role="group" aria-label="Cambios pendientes de aprobación">
                            <x-ui::stack gap="sm">
                                <strong>El asistente quiere hacer {{ count($this->pending) === 1 ? 'este cambio' : 'estos cambios' }}</strong>
                                <x-ui::list>
                                    @foreach ($this->pending as $change)
                                        <li wire:key="dock-pending-{{ $change['id'] }}">{{ $change['description'] }}</li>
                                    @endforeach
                                </x-ui::list>
                                <x-ui::actions>
                                    <x-ui::button size="sm" variant="ghost" wire:click="decide(false)" wire:loading.attr="disabled" wire:target="decide">No, déjalo</x-ui::button>
                                    <x-ui::button size="sm" icon="check" wire:click="decide(true)" wire:loading.attr="disabled" wire:target="decide">Sí, hazlo</x-ui::button>
                                </x-ui::actions>
                            </x-ui::stack>
                        </x-ui::panel>
                    @endif
                    <div wire:loading.flex wire:target="send" class="ui-chat">
                        <x-ui::chat-message role="user" author="Tú"><span wire:stream="question"></span></x-ui::chat-message>
                        <x-ui::chat-message role="assistant" author="Asistente"><span class="ui-chat-stream" wire:stream="answer"></span><x-ui::sr>Escribiendo…</x-ui::sr></x-ui::chat-message>
                    </div>
                    <div wire:loading.flex wire:target="decide" class="ui-chat">
                        <x-ui::chat-message role="assistant" author="Asistente"><span class="ui-chat-stream" wire:stream="answer"></span><x-ui::sr>Escribiendo…</x-ui::sr></x-ui::chat-message>
                    </div>
                </div>
            @endif

            <x-slot:composer>
                @if ($open)
                    <x-ui::form wire:submit="send">
                        <x-ui::textarea name="message" id="dock-message" label="Tu pregunta" rows="2" wire:model="message" maxlength="{{ \App\Services\Assistant\AssistantChat::MAX_MESSAGE }}"
                            x-init="$nextTick(() => $el.focus())"
                            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }" />
                        <x-ui::button type="submit" size="sm" icon="arrow-right" wire:loading.attr="disabled" wire:target="send">Enviar</x-ui::button>
                    </x-ui::form>
                @endif
            </x-slot:composer>
        </x-ui::dock>
    @endunless
</div>
