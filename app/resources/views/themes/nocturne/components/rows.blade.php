{{-- Contract: panel whose direct children are separated rows (one entry each). --}}
<div {{ $attributes->merge(['class' => 'ui-panel ui-rows']) }}>{{ $slot }}</div>
