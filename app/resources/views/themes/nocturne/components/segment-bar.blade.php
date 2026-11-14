@props(['segments', 'label'])
{{-- Contract: parts of a whole. segments = list of {key, label, text, percent}; key picks the tone (portal|other|safety|free). --}}
<div {{ $attributes->merge(['class' => 'ui-segments']) }}>
    <div class="ui-segments-bar" role="img" aria-label="{{ $label }}">
        @foreach ($segments as $segment)
            <span data-key="{{ $segment['key'] }}" style="width: {{ $segment['percent'] }}%"></span>
        @endforeach
    </div>
    <ul class="ui-segments-legend">
        @foreach ($segments as $segment)
            <li><span data-key="{{ $segment['key'] }}" aria-hidden="true"></span>{{ $segment['label'] }}: {{ $segment['text'] }}</li>
        @endforeach
    </ul>
</div>
