<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\AssistantSetting;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantChat;
use App\Services\Assistant\SemanticIndex;
use Laravel\Passport\Token;
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

    /** @return list<array{id: string, tool: string, description: string}> */
    #[Computed]
    public function pending(): array
    {
        if ($this->conversation === null) {
            return [];
        }
        try {
            return app(AssistantChat::class)->pending(Auth::user(), $this->conversation);
        } catch (RuntimeException) {
            return [];
        }
    }

    /** Approves or rejects every proposed change; the answer streams like a normal reply. */
    public function decide(bool $approve): void
    {
        $this->throttle('assistant');
        if ($this->conversation === null) {
            return;
        }
        try {
            $result = app(AssistantChat::class)->decide(Auth::user(), $this->conversation, $approve,
                fn (string $delta) => $this->stream(to: 'answer', content: $delta));
            $this->conversation = $result['conversation'];
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'bad');
        }
        unset($this->conversations, $this->messages, $this->pending);
    }

    /** 'off' (the owner does not allow it), 'ask' or 'on' for this person's file contents. */
    #[Computed]
    public function contentState(): string
    {
        if (! AssistantSetting::current()->content_allowed) {
            return 'off';
        }

        return app(AssistantAccess::class)->hasAcceptedContent(Auth::user()) ? 'on' : 'ask';
    }

    /** @return array{files: int, chunks: int}|null Prepared files, when search by meaning applies to this person. */
    #[Computed]
    public function semantic(): ?array
    {
        $index = app(SemanticIndex::class);

        return $index->denial(Auth::user()) === null ? $index->stats(Auth::user()) : null;
    }

    /**
     * External apps this person connected (MCP-05), one line per app.
     *
     * @return list<array{client: string, name: string, last: string|null}>
     */
    #[Computed]
    public function apps(): array
    {
        $apps = [];
        foreach (Auth::user()->tokens()->with('client')->where('revoked', false)->where('expires_at', '>', now()->subDays(30))->latest()->get() as $token) {
            $client = (string) $token->client_id;
            $apps[$client] ??= ['client' => $client, 'name' => Str::limit((string) ($token->client?->name ?? 'Aplicación'), 60), 'last' => $token->created_at?->diffForHumans()];
        }

        return array_values($apps);
    }

    /** Disconnects an app: every access and refresh token of this person for it is revoked. */
    public function disconnect(string $clientId): void
    {
        $this->throttle('assistant');
        $tokens = Auth::user()->tokens()->where('client_id', $clientId)->get();
        foreach ($tokens as $token) {
            /** @var Token $token */
            $token->revoke();
            $token->refreshToken?->revoke();
        }
        unset($this->apps);
        $this->toast('Aplicación desconectada.');
    }

    public function setContent(bool $accepted): void
    {
        $this->throttle('assistant');
        abort_if($accepted && ! AssistantSetting::current()->content_allowed, 403);
        app(AssistantAccess::class)->setContentConsent(Auth::user(), $accepted);
        unset($this->contentState);
        $this->toast($accepted ? 'El asistente ya puede leer el texto de tus archivos cuando se lo pidas.' : 'El asistente ya no puede leer el contenido de tus archivos.');
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
        unset($this->conversations, $this->messages, $this->pending);
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
    <x-ui::page-header title="Asistente" description="Pregunta por tus archivos, tu espacio o tus servicios. Solo ve lo tuyo y no cambia nada sin que lo apruebes." />

    @if ($this->contentState === 'ask')
        <x-ui::notice tone="info">
            ¿Quieres que el asistente pueda leer el texto de tus archivos (texto, Markdown, CSV, JSON y PDF) cuando se lo pidas{{ \App\Models\AssistantSetting::current()->semantic_enabled ? ' y prepararlos para buscar por lo que tratan' : '' }}? Lo que lea se envía al proveedor de IA que usa Multisuite. Puedes retirarlo cuando quieras.
            <x-ui::button size="sm" wire:click="setContent(true)">Permitir que lea mis archivos</x-ui::button>
        </x-ui::notice>
    @elseif ($this->contentState === 'on')
        <p class="ui-help">
            El asistente puede leer el texto de tus archivos cuando se lo pidas.
            @if ($this->semantic !== null)
                Búsqueda por significado: {{ $this->semantic['files'] }} {{ $this->semantic['files'] === 1 ? 'archivo preparado' : 'archivos preparados' }} (se actualiza cada 15 minutos).
            @endif
            <x-ui::link wire:click="setContent(false)">Dejar de permitirlo</x-ui::link>
        </p>
    @endif

    @if ($this->apps !== [])
        <x-ui::panel>
            <x-ui::heading :level="3">Aplicaciones conectadas</x-ui::heading>
            @foreach ($this->apps as $app)
                <x-ui::row wire:key="app-{{ $app['client'] }}">
                    <span>{{ $app['name'] }} <span class="ui-help">· conectada {{ $app['last'] }}</span></span>
                    <x-ui::button size="sm" variant="ghost" wire:click="disconnect('{{ $app['client'] }}')">Desconectar</x-ui::button>
                </x-ui::row>
            @endforeach
        </x-ui::panel>
    @endif

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
                    Por ejemplo: «¿Cuánto espacio me queda?», «Busca mis facturas de 2026»@if ($this->contentState === 'on'), «Resume el PDF del contrato»@endif o «¿Qué servicios tengo?».
                </x-ui::empty-state>
            @endif
            @foreach ($this->messages as $index => $turn)
                @if ($turn->role === 'user')
                    <x-ui::chat-message role="user" author="Tú" wire:key="turn-{{ $index }}">{{ $turn->content }}</x-ui::chat-message>
                @else
                    <x-ui::chat-message role="assistant" author="Asistente" wire:key="turn-{{ $index }}"><x-ui::prose>{!! $this->answer($turn->content) !!}</x-ui::prose></x-ui::chat-message>
                @endif
            @endforeach
            @if ($this->pending !== [])
                <x-ui::panel tone="dashed" role="group" aria-label="Cambios pendientes de aprobación">
                    <x-ui::stack gap="md">
                        <x-ui::heading :level="3">El asistente quiere hacer {{ count($this->pending) === 1 ? 'este cambio' : 'estos cambios' }}</x-ui::heading>
                        <x-ui::list>
                            @foreach ($this->pending as $change)
                                <li wire:key="pending-{{ $change['id'] }}">{{ $change['description'] }}</li>
                            @endforeach
                        </x-ui::list>
                        <x-ui::actions>
                            <x-ui::button variant="ghost" wire:click="decide(false)" wire:loading.attr="disabled" wire:target="decide">No, déjalo</x-ui::button>
                            <x-ui::button icon="check" wire:click="decide(true)" wire:loading.attr="disabled" wire:target="decide">Sí, hazlo</x-ui::button>
                        </x-ui::actions>
                    </x-ui::stack>
                </x-ui::panel>
            @endif
            <div wire:loading.flex wire:target="decide" class="ui-chat">
                <x-ui::chat-message role="assistant" author="Asistente"><span class="ui-chat-stream" wire:stream="answer"></span><x-ui::sr>Escribiendo…</x-ui::sr></x-ui::chat-message>
            </div>
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
