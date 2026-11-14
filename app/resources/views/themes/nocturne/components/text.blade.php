@props(['tone' => 'sub', 'as' => 'p'])
{{-- Contract: secondary copy. tone sub (supporting text) | muted (small notes). --}}
<{{ $as }} {{ $attributes->merge(['class' => $tone === 'muted' ? 'ui-muted' : 'ui-sub']) }}>{{ $slot }}</{{ $as }}>
