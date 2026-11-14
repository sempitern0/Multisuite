@props(['name', 'icon' => 'grid', 'status' => 'ready', 'statusLabel' => 'Disponible', 'href' => null])
{{--
    Contract: one-word service name, icon, short status. status ready|pending|soon|offline.
    Only pass `href` when the server allows opening it; without it the tile is informative.
--}}
@php
    $tone = ['ready' => 'ok', 'pending' => 'warn', 'soon' => 'muted', 'offline' => 'bad'][$status] ?? 'muted';
    $tag = $href !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href !== null) href="{{ $href }}" aria-label="Abrir {{ $name }}" @endif data-status="{{ $status }}" {{ $attributes->merge(['class' => 'ui-service-tile']) }}>
    <span class="ui-tile-icon"><x-ui::icon :name="$icon" /></span>
    <span class="ui-tile-title">{{ $name }}</span>
    <span class="ui-tile-bottom">
        <x-ui::status :tone="$tone">{{ $statusLabel }}</x-ui::status>
        @if ($href !== null)<span class="ui-tile-arrow"><x-ui::icon name="arrow-right" /></span>@endif
    </span>
    {{ $slot }}
</{{ $tag }}>
