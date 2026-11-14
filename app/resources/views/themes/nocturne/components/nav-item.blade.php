@props(['href', 'icon', 'active' => false])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->merge(['class' => 'ui-nav-item']) }}>
    <x-ui::icon :name="$icon" />
    <span class="ui-hide-compact">{{ $slot }}</span>
</a>
