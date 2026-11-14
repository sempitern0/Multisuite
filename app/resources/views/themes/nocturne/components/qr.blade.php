@props(['label'])
{{-- Contract: QR code (SVG in the slot) on a light tile so cameras can read it on a dark theme. --}}
<div {{ $attributes->merge(['class' => 'ui-qr']) }} role="img" aria-label="{{ $label }}">{{ $slot }}</div>
