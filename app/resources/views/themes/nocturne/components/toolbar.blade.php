@props(['surface' => false])
{{-- Contract: filters or actions above a list; optional `end` slot for actions aligned to the right. surface = command bar on its own panel, set apart from the content below. --}}
<div {{ $attributes->merge(['class' => 'ui-toolbar'.($surface ? ' ui-toolbar-surface' : '')]) }}>
    {{ $slot }}
    @isset($end)<div class="ui-toolbar-end">{{ $end }}</div>@endisset
</div>
