{{-- Contract: two things on one line, pushed apart (title + state); wraps on narrow screens. --}}
<div {{ $attributes->merge(['class' => 'ui-row']) }}>{{ $slot }}</div>
