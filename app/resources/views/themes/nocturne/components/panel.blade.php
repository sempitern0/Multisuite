@props(['pad' => true])
<section {{ $attributes->merge(['class' => 'ui-panel'.($pad ? ' ui-panel-pad' : '')]) }}>{{ $slot }}</section>
