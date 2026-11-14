@props(['value', 'label'])
{{-- Contract: progress 0-100 with an accessible name; the caption goes in the slot. --}}
@php($value = max(0, min(100, (int) $value)))
<div {{ $attributes->merge(['class' => 'ui-meter-block']) }}>
    <div class="ui-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $value }}" aria-label="{{ $label }}" data-level="{{ $value >= 90 ? 'high' : 'normal' }}">
        <span style="width: {{ $value }}%"></span>
    </div>
    @if (trim((string) $slot) !== '')<p class="ui-sub ui-meter-caption">{{ $slot }}</p>@endif
</div>
