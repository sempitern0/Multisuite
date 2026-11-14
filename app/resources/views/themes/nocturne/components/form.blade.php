@props(['action' => null, 'method' => 'POST'])
{{--
    Contract: form with the theme's field rhythm. With `action` it is a classic POST form
    (CSRF added, other verbs spoofed); without it, a Livewire form (pass wire:submit).
--}}
@if ($action !== null)
    <form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'ui-form']) }}>
        @csrf
        @if (strtoupper($method) !== 'POST') @method($method) @endif
        {{ $slot }}
    </form>
@else
    <form {{ $attributes->merge(['class' => 'ui-form']) }}>{{ $slot }}</form>
@endif
