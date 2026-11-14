@props(['label'])
<section aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-service-grid']) }}>{{ $slot }}</section>
