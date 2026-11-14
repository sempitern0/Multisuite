@props(['items', 'label'])
{{-- Contract: secondary navigation; items = list of {label, href, active}. Uses SPA navigation. --}}
<nav aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-subnav']) }}>
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" wire:navigate @if ($item['active']) aria-current="page" @endif class="ui-subnav-item">{{ $item['label'] }}</a>
    @endforeach
</nav>
