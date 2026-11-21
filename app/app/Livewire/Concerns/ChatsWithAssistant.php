<?php

namespace App\Livewire\Concerns;

use App\Services\Assistant\AssistantChat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use RuntimeException;

/**
 * One conversation with the assistant, shared by the Asistente page and the floating panel.
 * The using component declares `public ?string $conversation` and `public string $message`
 * and uses ThrottlesActions and NotifiesInterface. Every read goes through AssistantChat,
 * which checks that the conversation belongs to the person.
 */
trait ChatsWithAssistant
{
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
            $this->conversationChanged();

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
            $this->conversationChanged();
        } catch (RuntimeException $exception) {
            $this->message = $text;
            $this->addError('message', $exception->getMessage());
        }
        $this->forgetChatState();
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
            $this->conversationChanged();
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'bad');
        }
        $this->forgetChatState();
    }

    public function start(): void
    {
        $this->conversation = null;
        $this->message = '';
        $this->conversationChanged();
        $this->forgetChatState();
    }

    public function answer(string $markdown): string
    {
        // Same safe rendering as Markdown previews. Images are dropped: an answer shaped by a
        // malicious file must never make the browser call an outside address (the floating
        // panel lives on pages without the Asistente page's CSP).
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);

        return (string) preg_replace('/<img\b[^>]*>/i', '', $html);
    }

    /** Hook for components that remember the open conversation elsewhere. */
    protected function conversationChanged(): void {}

    protected function forgetChatState(): void
    {
        unset($this->messages, $this->pending);
    }
}
