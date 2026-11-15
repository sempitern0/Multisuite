@props(['entry'])
{{-- Download and move-to-trash for one file, shared by the Detalles table and the Lista/Galería tiles. The page provides trash(). --}}
<x-ui::button variant="ghost" size="sm" icon="download" label="Descargar" :href="route('files.content', ['fileId' => $entry->id, 'download' => 1])" :aria-label="'Descargar '.$entry->name" />
<x-ui::button variant="ghost" size="sm" icon="trash" label="Mover a la papelera" wire:click="trash({{ $entry->id }})" :aria-label="'Mover a la papelera '.$entry->name" />
