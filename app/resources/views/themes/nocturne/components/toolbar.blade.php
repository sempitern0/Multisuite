{{-- Contract: filters or actions above a list; optional `end` slot for actions aligned to the right. --}}
<div {{ $attributes->merge(['class' => 'ui-toolbar']) }}>
    {{ $slot }}
    @isset($end)<div class="ui-toolbar-end">{{ $end }}</div>@endisset
</div>
