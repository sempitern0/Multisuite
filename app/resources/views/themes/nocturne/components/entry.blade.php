@props(['href', 'icon' => 'document', 'spa' => false])
{{-- Contract: name of a file or folder with its icon, as a link. spa navigates without a full load. --}}
<a href="{{ $href }}" @if ($spa) wire:navigate @endif {{ $attributes->merge(['class' => 'ui-entry']) }}>
    <x-ui::icon :name="$icon" /><span>{{ $slot }}</span>
</a>
