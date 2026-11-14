@props(['tone' => null])
<span {{ $attributes->merge(['class' => 'ui-tag'.($tone ? ' ui-tag-'.$tone : '')]) }}>{{ $slot }}</span>
