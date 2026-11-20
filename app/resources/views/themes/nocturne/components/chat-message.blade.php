@props(['role' => 'assistant', 'author' => null])
{{--
    Contract: one turn of a conversation. role user|assistant. The slot is already safe HTML
    (escaped text or sanitised Markdown). Pass `author` for the accessible label.
--}}
<article {{ $attributes->merge(['class' => 'ui-chat-msg ui-chat-msg-'.($role === 'user' ? 'user' : 'assistant')]) }} @if ($author) aria-label="{{ $author }}" @endif>
    <div class="ui-chat-bubble">{{ $slot }}</div>
</article>
