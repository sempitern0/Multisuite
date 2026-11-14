@props(['items', 'label' => 'Ubicación'])
{{-- Contract: where the person is. items = list of {label, href}; the last one is the current place. --}}
<nav aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-crumbs']) }}>
    @foreach ($items as $item)
        @if (! $loop->first)<span aria-hidden="true">/</span>@endif
        @if ($loop->last)
            <span aria-current="page">{{ $item['label'] }}</span>
        @else
            <a href="{{ $item['href'] }}" wire:navigate>{{ $item['label'] }}</a>
        @endif
    @endforeach
</nav>
