@props(['items', 'action', 'label', 'legend' => ['Ingresos', 'Gastos']])
{{--
    Contract: income and expense per period as paired bars; each period is a button that calls
    the Livewire method `action` with its key. items = list of [key, short (axis label), name,
    income, expense (numbers to scale), summary (spoken text with the amounts), current (bool)].
--}}
@php($max = max(1, ...array_map(fn ($item) => max($item['income'], $item['expense']), $items)))
<figure {{ $attributes->merge(['class' => 'ui-month-chart']) }}>
    <figcaption class="ui-month-legend">
        <span class="ui-sr-only">{{ $label }}. </span>
        <span data-series="income">{{ $legend[0] }}</span>
        <span data-series="expense">{{ $legend[1] }}</span>
    </figcaption>
    <div class="ui-month-bars" role="group" aria-label="{{ $label }}">
        @foreach ($items as $item)
            <button type="button" class="ui-month" wire:key="chart-{{ $item['key'] }}" wire:click="{{ $action }}({{ $item['key'] }})"
                @if ($item['current']) aria-current="true" @endif aria-label="{{ $item['name'] }}: {{ $item['summary'] }}" title="{{ $item['name'] }}: {{ $item['summary'] }}">
                <span class="ui-month-pair" aria-hidden="true">
                    <span data-series="income" style="height: {{ round($item['income'] * 100 / $max, 1) }}%"></span>
                    <span data-series="expense" style="height: {{ round($item['expense'] * 100 / $max, 1) }}%"></span>
                </span>
                <span class="ui-month-label" aria-hidden="true">{{ $item['short'] }}</span>
            </button>
        @endforeach
    </div>
</figure>
