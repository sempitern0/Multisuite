@props(['action' => null, 'method' => 'POST', 'inline' => false])
{{--
    Contract: form with the theme's field rhythm. With `action` it is a classic POST form
    (CSRF added, other verbs spoofed); without it, a Livewire form (pass wire:submit).
    inline puts one field and its button on the same line, aligned (wraps on small screens).
--}}
@php($class = 'ui-form'.($inline ? ' ui-form-inline' : ''))
@if ($action !== null)
    <form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => $class]) }}>
        @csrf
        @if (strtoupper($method) !== 'POST') @method($method) @endif
        {{ $slot }}
    </form>
@else
    <form {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</form>
@endif
