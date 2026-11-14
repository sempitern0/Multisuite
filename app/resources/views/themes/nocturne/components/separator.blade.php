{{-- Contract: horizontal divider with an optional short text in the middle. --}}
<div {{ $attributes->merge(['class' => 'ui-separator']) }} role="separator"><span>{{ $slot }}</span></div>
