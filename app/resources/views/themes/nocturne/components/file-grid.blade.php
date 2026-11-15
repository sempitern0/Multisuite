@props(['view' => 'gallery', 'label'])
{{-- Contract: files and folders as tiles. view gallery (thumbnails) | list (compact names in columns). Children: x-ui::file-card. --}}
<ul aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'ui-files', 'data-view' => $view]) }} wire:loading.class="is-loading">{{ $slot }}</ul>
