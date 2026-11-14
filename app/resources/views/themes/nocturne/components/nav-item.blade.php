@props(['href', 'icon', 'active' => false, 'spa' => true])
<a href="{{ $href }}" @if ($spa) wire:navigate @endif @if ($active) aria-current="page" @endif {{ $attributes->merge(['class' => 'ui-nav-item']) }}>
    <x-ui::icon :name="$icon" />
    <span class="ui-hide-compact">{{ $slot }}</span>
</a>
