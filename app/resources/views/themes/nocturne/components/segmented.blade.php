@props(['label'])
{{-- Contract: group of mutually exclusive buttons (e.g. a view switch). Give each x-ui::button aria-pressed="true|false". --}}
<div role="group" aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-segmented']) }}>{{ $slot }}</div>
