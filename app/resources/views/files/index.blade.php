@php
    $fmt = fn (?int $bytes): string => \App\Support\Bytes::format($bytes);
    $describe = fn (array $summary): string => $summary['items'].' '.($summary['items'] === 1 ? 'elemento' : 'elementos').' · '.\App\Support\Bytes::format($summary['bytes']);
    $icon = fn (?string $mime): string => match (true) {
        str_starts_with((string) $mime, 'image/') => '🖼️',
        $mime === 'application/pdf' => '📕',
        str_starts_with((string) $mime, 'audio/') => '🎵',
        str_starts_with((string) $mime, 'text/') || $mime === 'application/json' => '📝',
        default => '📄',
    };
@endphp
<x-layouts::app :title="__('Mis archivos')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-2">
                <flux:heading size="xl">Mis archivos</flux:heading>
                <nav aria-label="Ubicación" class="flex flex-wrap items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <a href="{{ route('files.index') }}" class="underline-offset-4 hover:underline" @if ($breadcrumbs === []) aria-current="page" @endif>Inicio</a>
                    @foreach ($breadcrumbs as $crumb)
                        <span aria-hidden="true">/</span>
                        <a href="{{ route('files.folder', $crumb) }}" class="underline-offset-4 hover:underline" @if ($loop->last) aria-current="page" @endif>{{ $crumb->name }}</a>
                    @endforeach
                </nav>
                <flux:text>En esta carpeta: {{ $describe($summaries[$folder?->id ?? 0] ?? ['items' => 0, 'bytes' => 0]) }}</flux:text>
            </div>
            @if ($limit > 0)
                <div class="min-w-56 space-y-1 text-sm">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ \App\Support\Bytes::percent($used, $limit) }}" aria-label="Espacio usado">
                        <div class="h-full bg-teal-600" style="width: {{ \App\Support\Bytes::percent($used, $limit) }}%"></div>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">{{ $fmt($used) }} de {{ $fmt($limit) }}</p>
                </div>
            @endif
        </header>

        @if (session('status'))
            <p role="status" class="rounded-lg border border-teal-300 p-4 dark:border-teal-800">{{ session('status') }}</p>
        @endif
        @foreach (['folder', 'folder_name', 'folder_delete', 'name'] as $field)
            @error($field)
                <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">{{ $message }}</p>
            @enderror
        @endforeach

        @if (! $enabled)
            <p class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">Los archivos privados todavía no están activados en este servidor. El responsable técnico debe preparar una ubicación privada (docs/STORAGE.md). Mientras tanto puedes ver lo que ya tengas guardado.</p>
        @elseif ($limit === 0)
            <p class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">Aún no tienes espacio asignado. Pide a un administrador que te asigne espacio en <strong>Almacenamiento</strong>.</p>
        @endif

        <section aria-label="Acciones" class="flex flex-wrap items-end gap-4">
            @if ($enabled && $limit > 0)
                <div data-uploader data-store-url="{{ route('files.store') }}" data-upload-url="{{ url('uploads') }}" data-folder="{{ $folder?->id }}" data-max-bytes="{{ $maxBytes }}" data-csrf="{{ csrf_token() }}" class="space-y-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-teal-800 px-4 py-3 font-medium text-white hover:bg-teal-900 focus-within:outline-2 focus-within:outline-offset-4 focus-within:outline-teal-600">
                        <span>Subir archivos</span>
                        <input type="file" multiple class="sr-only" data-uploader-input>
                    </label>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">Hasta {{ $fmt($maxBytes) }} por archivo. Documentos, PDF, imágenes, texto y audio.</p>
                </div>
            @endif
            <flux:button :href="route('files.trash')" icon="trash">Papelera</flux:button>
            <flux:button :href="route('files.exports.index')" icon="archive-box-arrow-down">Descargas ZIP</flux:button>
            <form method="POST" action="{{ route('files.folders.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <input type="hidden" name="parent" value="{{ $folder?->id }}">
                <flux:input name="name" label="Nueva carpeta" maxlength="120" required placeholder="Ej. Facturas 2026" />
                <flux:button type="submit">Crear carpeta</flux:button>
            </form>
        </section>

        <ol data-uploader-list class="space-y-2" aria-live="polite"></ol>
        @if ($pending > 0)
            <flux:text>{{ $pending }} {{ $pending === 1 ? 'subida sin terminar' : 'subidas sin terminar' }}. Si cerraste la página a mitad, el espacio se libera solo al cabo de unos 30–40 minutos.</flux:text>
        @endif

        @error('export')
            <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">{{ $message }}</p>
        @enderror
        <form method="POST" action="{{ route('files.exports.store') }}" id="zip-form" class="space-y-3">
        @csrf
        <input type="hidden" name="folder" value="{{ $folder?->id }}">
        @if ($folders->isNotEmpty() || $entries->isNotEmpty())
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" name="scope" value="selection" size="sm">Descargar selección en ZIP</flux:button>
                <flux:button type="submit" name="scope" value="{{ $folder === null ? 'all' : 'folder' }}" size="sm">{{ $folder === null ? 'Exportar todo en ZIP' : 'Descargar esta carpeta en ZIP' }}</flux:button>
            </div>
        @endif
        <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Contenido de la carpeta">
            @foreach ($folders as $child)
                <li class="flex items-center justify-between gap-4 p-4">
                    <input type="checkbox" name="folders[]" value="{{ $child->id }}" class="size-4 shrink-0" aria-label="Seleccionar carpeta {{ $child->name }}">
                    <a href="{{ route('files.folder', $child) }}" class="me-auto flex min-w-0 items-center gap-3 font-medium underline-offset-4 hover:underline">
                        <span aria-hidden="true">📁</span><span class="truncate">{{ $child->name }}</span>
                    </a>
                    <flux:text>{{ $describe($summaries[$child->id] ?? ['items' => 0, 'bytes' => 0]) }}</flux:text>
                </li>
            @endforeach
            @foreach ($entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                    <input type="checkbox" name="files[]" value="{{ $entry->id }}" class="size-4 shrink-0" aria-label="Seleccionar {{ $entry->name }}">
                    <a href="{{ route('files.show', $entry) }}" class="me-auto flex min-w-0 items-center gap-3 underline-offset-4 hover:underline">
                        <span aria-hidden="true">{{ $icon($entry->mime) }}</span><span class="truncate">{{ $entry->name }}</span>
                    </a>
                    <div class="flex items-center gap-4 text-sm text-zinc-600 dark:text-zinc-300">
                        <span>{{ $fmt((int) $entry->size) }}</span>
                        <a href="{{ route('files.content', ['fileId' => $entry->id, 'download' => 1]) }}" class="underline" aria-label="Descargar {{ $entry->name }}">Descargar</a>
                    </div>
                </li>
            @endforeach
            @if ($folders->isEmpty() && $entries->isEmpty())
                <li class="p-5 text-zinc-600 dark:text-zinc-300">Esta carpeta está vacía.</li>
            @endif
        </ul>
        </form>

        @if ($folder !== null)
            <section aria-labelledby="folder-settings" class="grid gap-4 rounded-xl border border-zinc-200 p-5 sm:grid-cols-2 dark:border-zinc-700">
                <h2 id="folder-settings" class="sr-only">Opciones de la carpeta</h2>
                <form method="POST" action="{{ route('files.folders.update', ['folderId' => $folder->id]) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    @method('PATCH')
                    <flux:input name="name" label="Renombrar carpeta" :value="$folder->name" maxlength="120" required />
                    <flux:button type="submit">Renombrar</flux:button>
                </form>
                <form method="POST" action="{{ route('files.folders.destroy', ['folderId' => $folder->id]) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    @method('DELETE')
                    <flux:text class="w-full">Solo se pueden eliminar carpetas vacías.</flux:text>
                    <flux:button type="submit" variant="danger">Eliminar carpeta</flux:button>
                </form>
            </section>
        @endif
    </div>
    @vite(['resources/js/files.js'])
</x-layouts::app>
