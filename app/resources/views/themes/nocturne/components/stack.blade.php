@props(['gap' => 'md'])
{{-- Contract: vertical rhythm between children. gap sm | md | lg. --}}
<div {{ $attributes->merge(['class' => 'ui-stack ui-stack-'.$gap]) }}>{{ $slot }}</div>
