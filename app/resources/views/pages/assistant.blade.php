<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\Assistant\AssistantChat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Asistente')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    /** Conversation open; checked against its owner on every read. */
    #[Url(as: 'c', except: null)]
    public ?string $conversation = null;

    public string $message = '';

    public function boot(): void
    {
        Gate::authorize('use-assistant');
    }

    #[Computed]
    public function conversations(): Collection
    {
        return app(AssistantChat::class)->conversations(Auth::user());
    }

    #[Computed]
    public function messages(): Collection
    {
        if ($this->conversation === null) {
            return new Collection;
        }
        try {
            return app(AssistantChat::class)->messages(Auth::user(), $this->conversation);
        } catch (RuntimeException) {
            $this->conversation = null;

            return new Collection;
        }
    }

    public function send(): void
    {
        $this->throttle('assistant');
        $this->validate(['message' => ['required', 'string', 'max:'.AssistantChat::MAX_MESSAGE]], [
            'message.required' => 'Escribe una pregunta.',
            'message.max' => 'La pregunta es demasiado larga (máximo '.AssistantChat::MAX_MESSAGE.' caracteres).',
        ]);
        $text = trim($this->message);
        $this->message = '';
        $this->stream(to: 'question', content: e($text), replace: true);
        try {
            // Each piece of the answer is escaped before it reaches the page (insertAdjacentHTML).
            $result = app(AssistantChat::class)->send(Auth::user(), $this->conversation, $text,
                fn (string $delta) => $this->stream(to: 'answer', content: $delta));
            $this->conversation = $result['conversation'];
        } catch (RuntimeException $exception) {
            $this->message = $text;
            $this->addError('message', $exception->getMessage());
        }
        unset($this->conversations, $this->messages);
    }

    public function start(): void
    {
        $this->conversation = null;
        $this->message = '';
        unset($this->messages);
    }

    public function open(string $id): void
    {
        $this->conversation = $id;
        unset($this->messages);
    }

    public function remove(string $id): void
    {
        $this->throttle('assistant');
        try {
            app(AssistantChat::class)->delete(Auth::user(), $id);
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'bad');

            return;
        }
        if ($this->conversation === $id) {
            $this->conversation = null;
        }
        unset($this->conversations, $this->messages);
        $this->toast('Conversación borrada.');
    }

    public function answer(string $markdown): string
    {
        // Same safe rendering as Markdown previews; the page's CSP blocks remote images.
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
    }
}; ?>

<div>
    <x-ui::page-header title="Asistente" description="Pregunta por tus archivos, tu espacio o tus servicios. Solo ve lo tuyo y todavía no puede cambiar nada." />

    <x-ui::chat-layout>
        <x-slot:aside>
            <x-ui::button icon="plus" variant="secondary" wire:click="start" block>Nueva conversación</x-ui::button>
            @forelse ($this->conversations as $item)
                <x-ui::row wire:key="conversation-{{ $item->id }}">
                    <x-ui::button variant="{{ $conversation === $item->id ? 'primary' : 'ghost' }}" size="sm" wire:click="open('{{ $item->id }}')" block
                        :aria-current="$conversation === $item->id ? 'true' : null">{{ Str::limit($item->title, 40) }}</x-ui::button>
                    <x-ui::confirm title="¿Borrar esta conversación?">
                        <x-slot:trigger><x-ui::button icon="trash" variant="ghost" size="sm" :label="'Borrar '.Str::limit($item->title, 40)" /></x-slot:trigger>
                        Se borra para siempre. Las conversaciones también se borran solas tras 30 días sin usarse.
                        <x-slot:action><x-ui::button variant="danger" wire:click="remove('{{ $item->id }}')">Borrar</x-ui::button></x-slot:action>
                    </x-ui::confirm>
                </x-ui::row>
            @empty
                <p class="ui-help">Aún no tienes conversaciones.</p>
            @endforelse
        </x-slot:aside>

        <div class="ui-chat" aria-live="polite">
            @if ($this->messages->isEmpty())
                <x-ui::empty-state icon="diamond" title="¿En qué te ayudo?">
                    Por ejemplo: «¿Cuánto espacio me queda?», «Busca mis facturas de 2026» o «¿Qué servicios tengo?».
                </x-ui::empty-state>
            @endif
            @foreach ($this->messages as $index => $turn)
                @if ($turn->role === 'user')
                    <x-ui::chat-message role="user" author="Tú" wire:key="turn-{{ $index }}">{{ $turn->content }}</x-ui::chat-message>
                @else
                    <x-ui::chat-message role="assistant" author="Asistente" wire:key="turn-{{ $index }}"><x-ui::prose>{!! $this->answer($turn->content) !!}</x-ui::prose></x-ui::chat-message>
                @endif
            @endforeach
            <div wire:loading.flex wire:target="send" class="ui-chat">
                <x-ui::chat-message role="user" author="Tú"><span wire:stream="question"></span></x-ui::chat-message>
                <x-ui::chat-message role="assistant" author="Asistente"><span class="ui-chat-stream" wire:stream="answer"></span><x-ui::sr>Escribiendo…</x-ui::sr></x-ui::chat-message>
            </div>
        </div>

        <x-ui::form wire:submit="send">
            <x-ui::textarea name="message" label="Tu pregunta" wire:model="message" maxlength="{{ \App\Services\Assistant\AssistantChat::MAX_MESSAGE }}"
                x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                help="Intro para enviar; Mayúsculas + Intro para una línea nueva. Las respuestas las genera un proveedor de IA externo y pueden equivocarse." />
            <x-ui::button type="submit" icon="arrow-right" wire:loading.attr="disabled" wire:target="send">Enviar</x-ui::button>
        </x-ui::form>
    </x-ui::chat-layout>
</div>
