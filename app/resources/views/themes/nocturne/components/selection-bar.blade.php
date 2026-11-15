@props(['show', 'label' => 'Acciones de la selección'])
{{--
    Contract: contextual bar for the items the person has selected (Gmail/Drive pattern).
    `show` is an Alpine expression (e.g. based on $wire state) so it appears without a
    server round trip; the slot holds a count (x-text) and the bulk actions. Stays at the
    top of the screen while scrolling.
--}}
<div x-cloak x-show="{{ $show }}" role="region" aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-selection-bar']) }}>{{ $slot }}</div>
