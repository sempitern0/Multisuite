@props(['items', 'label', 'tone' => null])
{{-- Contract: share of a total by item. items = list of [label, value (text), percent 0-100]. --}}
<ul {{ $attributes->merge(['class' => 'ui-breakdown']) }} aria-label="{{ $label }}" @if ($tone) data-tone="{{ $tone }}" @endif>
    @foreach ($items as $item)
        <li>
            <span class="ui-breakdown-label">{{ $item['label'] }}</span>
            <span class="ui-breakdown-value">{{ $item['value'] }} <span class="ui-breakdown-percent">{{ $item['percent'] }} %</span></span>
            <span class="ui-breakdown-bar" aria-hidden="true"><span style="width: {{ max(0, min(100, $item['percent'])) }}%"></span></span>
        </li>
    @endforeach
</ul>
