@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'iconEnd' => null, 'block' => false, 'type' => 'button', 'label' => null])
{{--
    Contract: variant primary|secondary|ghost|danger, size sm, href renders a link.
    Icon-only: give `icon` and `label` with no slot; label becomes the tooltip and the
    accessible name (an explicit aria-label, e.g. naming the item, takes precedence).
--}}
@php
    $iconOnly = $icon !== null && $label !== null && $slot->isEmpty();
    $classes = 'ui-btn ui-btn-'.$variant.($size === 'sm' ? ' ui-btn-sm' : '').($block ? ' ui-btn-block' : '').($iconOnly ? ' ui-btn-icon' : '');
    $extra = $iconOnly ? ['title' => $label, 'aria-label' => $attributes->get('aria-label', $label)] : [];
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes] + $extra) }}>@if ($icon)<x-ui::icon :name="$icon" />@endif{{ $slot }}@if ($iconEnd)<x-ui::icon :name="$iconEnd" />@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes] + $extra) }}>@if ($icon)<x-ui::icon :name="$icon" />@endif{{ $slot }}@if ($iconEnd)<x-ui::icon :name="$iconEnd" />@endif</button>
@endif
