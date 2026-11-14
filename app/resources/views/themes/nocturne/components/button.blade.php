@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'iconEnd' => null, 'block' => false, 'type' => 'button'])
{{-- Contract: variant primary|secondary|ghost|danger, size sm, href renders a link. --}}
@php
    $classes = 'ui-btn ui-btn-'.$variant.($size === 'sm' ? ' ui-btn-sm' : '').($block ? ' ui-btn-block' : '');
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui::icon :name="$icon" />@endif{{ $slot }}@if ($iconEnd)<x-ui::icon :name="$iconEnd" />@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui::icon :name="$icon" />@endif{{ $slot }}@if ($iconEnd)<x-ui::icon :name="$iconEnd" />@endif</button>
@endif
