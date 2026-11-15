@props(['name', 'sub' => null, 'src' => null, 'initials' => '', 'size' => 'sm'])
{{-- Contract: avatar + name with an optional secondary line. size sm (lists) | lg (a person's own page header). --}}
<span {{ $attributes->merge(['class' => 'ui-person-cell ui-person-cell-'.$size]) }}>
    <x-ui::avatar :src="$src" :initials="$initials" :size="$size" />
    <span class="ui-person-cell-text">
        <span class="ui-cell-main">{{ $name }}</span>
        @if (filled($sub))<span class="ui-cell-sub">{{ $sub }}</span>@endif
    </span>
</span>
