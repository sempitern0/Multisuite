@props(['label', 'paginator' => null, 'empty' => 'No hay nada que mostrar.'])
{{--
    Contract: data table. Slots: `head` (a <tr> of <th>) and the default slot (<tr> rows).
    Give each <td> a data-label (column name) for the stacked mobile layout and
    data-align="end" for action cells. Pass a Livewire paginator to get the footer.
    Render an empty row yourself with <x-ui::table-empty> when there are no rows.
--}}
<div {{ $attributes->merge(['class' => 'ui-table-wrap']) }} wire:loading.class="is-loading">
    <div class="ui-table-scroll">
        <table class="ui-table">
            <caption class="ui-sr-only">{{ $label }}</caption>
            <thead>{{ $head }}</thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
    @if ($paginator !== null && $paginator->total() > 0)
        <div class="ui-table-foot">
            <span>{{ $paginator->total() }} {{ $paginator->total() === 1 ? 'resultado' : 'resultados' }}</span>
            @if ($paginator->hasPages())
                <x-ui::pagination :paginator="$paginator" />
            @endif
        </div>
    @endif
</div>
