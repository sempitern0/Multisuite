@props(['href', 'icon' => 'document', 'spa' => false])
{{-- Contract: name of a file or folder with its icon, as a link. spa navigates without a full load. Long names are shortened in tables; the full name stays in the tooltip. --}}
<a href="{{ $href }}" @if ($spa) wire:navigate @endif {{ $attributes->merge(['class' => 'ui-entry', 'title' => trim(strip_tags((string) $slot))]) }}>
    <x-ui::icon :name="$icon" /><span>{{ $slot }}</span>
</a>
