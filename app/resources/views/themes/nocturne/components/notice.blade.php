@props(['tone' => 'ok'])
{{-- Contract: tone ok|warn|bad|info. Use for results confirmed by the server and for errors. --}}
@php($icon = ['ok' => 'check', 'warn' => 'alert', 'bad' => 'alert', 'info' => 'info'][$tone] ?? 'info')
<div {{ $attributes->merge(['class' => 'ui-notice'.($tone === 'ok' ? '' : ' ui-notice-'.$tone), 'role' => in_array($tone, ['bad', 'warn'], true) ? 'alert' : 'status']) }}>
    <x-ui::icon :name="$icon" />
    <div>{{ $slot }}</div>
</div>
