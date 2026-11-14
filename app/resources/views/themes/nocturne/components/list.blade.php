{{-- Contract: short bulleted explanation; pass <li> items. --}}
<ul {{ $attributes->merge(['class' => 'ui-list']) }}>{{ $slot }}</ul>
