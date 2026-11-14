@props(['pad' => true, 'tone' => null])
{{-- Contract: surface. tone danger marks irreversible actions; tone dashed marks something not set up yet. --}}
<section {{ $attributes->merge(['class' => 'ui-panel'.($pad ? ' ui-panel-pad' : '').($tone ? ' ui-panel-'.$tone : '')]) }}>{{ $slot }}</section>
