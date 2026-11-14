@props(['action', 'method' => 'POST'])
{{-- Contract: POST form with CSRF and the theme's field rhythm; other verbs are spoofed. --}}
<form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'ui-form']) }}>
    @csrf
    @if (strtoupper($method) !== 'POST') @method($method) @endif
    {{ $slot }}
</form>
