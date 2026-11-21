@props(['items'])
{{--
    Contract: headline figures as cards. items = list of [label, value, tone (ok|bad|accent|null),
    symbol (currency sign shown as a badge, or null), sub (one short line, or null)].
--}}
<dl {{ $attributes->merge(['class' => 'ui-metrics']) }}>
    @foreach ($items as $item)
        <div class="ui-metric" @if ($item['tone'] ?? null) data-tone="{{ $item['tone'] }}" @endif>
            <dt>
                @if ($item['symbol'] ?? null)<span class="ui-metric-symbol" aria-hidden="true">{{ $item['symbol'] }}</span>@endif
                <span>{{ $item['label'] }}</span>
            </dt>
            <dd>{{ $item['value'] }}</dd>
            @if ($item['sub'] ?? null)<dd class="ui-metric-sub">{{ $item['sub'] }}</dd>@endif
        </div>
    @endforeach
</dl>
