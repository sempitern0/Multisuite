@props(['src' => null, 'initials' => '', 'size' => 'md', 'label' => null, 'live' => false])
{{--
    Contract: a person's photo, or their initials when there is none. size sm|md|lg|xl.
    label = accessible name when the avatar stands alone; without it the avatar is decorative
    (the name is next to it). live = the signed-in person's own avatar: follows the
    `avatar-changed` browser event so a new photo shows everywhere without reloading.
--}}
@php($a11y = $label ? ['role' => 'img', 'aria-label' => $label] : ['aria-hidden' => 'true'])
@if ($live)
    <span {{ $attributes->merge(['class' => 'ui-avatar ui-avatar-'.$size] + $a11y) }} x-data="{ src: @js($src) }" x-on:avatar-changed.window="src = $event.detail.url">
        <img x-show="src" x-bind:src="src" alt="" decoding="async" @if (! $src) x-cloak @endif @if ($src) src="{{ $src }}" @endif>
        <span x-show="! src" @if ($src) x-cloak @endif>{{ $initials }}</span>
    </span>
@else
    <span {{ $attributes->merge(['class' => 'ui-avatar ui-avatar-'.$size] + $a11y) }}>
        @if ($src)<img src="{{ $src }}" alt="" loading="lazy" decoding="async">@else{{ $initials }}@endif
    </span>
@endif
