@props(['width' => 'form'])
{{-- Contract: limits line length. width form (forms and settings) | text (reading). --}}
<div {{ $attributes->merge(['class' => 'ui-container ui-container-'.$width]) }}>{{ $slot }}</div>
