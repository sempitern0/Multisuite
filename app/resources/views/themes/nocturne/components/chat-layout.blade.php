{{-- Contract: conversation list (`aside` slot) beside the conversation (default slot); stacks on small screens. --}}
<div {{ $attributes->merge(['class' => 'ui-chat-layout']) }}>
    <aside class="ui-chat-aside">{{ $aside }}</aside>
    <section class="ui-chat-main">{{ $slot }}</section>
</div>
