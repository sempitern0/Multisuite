@props(['tone' => 'ok'])
{{-- Contract: tone ok|warn|bad|muted; short text after a coloured dot. --}}
<span {{ $attributes->merge(['class' => 'ui-status'.($tone === 'ok' ? '' : ' ui-status-'.$tone)]) }}>{{ $slot }}</span>
