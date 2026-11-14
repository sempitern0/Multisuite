<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\Folder;
use App\Models\UserStorageQuota;
use App\Services\Files\FileExports;
use App\Services\Files\PersonalFiles;
use App\Support\DomainErrors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Mis archivos')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    #[Locked]
    public ?int $folderId = null;

    public string $newFolder = '';

    public string $folderName = '';

    /** @var list<int|string> */
    public array $selectedFiles = [];

    /** @var list<int|string> */
    public array $selectedFolders = [];

    public function mount(?int $folderId = null): void
    {
        $this->folderId = $folderId;
        $this->folderName = $this->folder->name ?? '';
    }

    /** Owner-scoped lookup (someone else's folder is "not found") on every request. */
    #[Computed]
    public function folder(): ?Folder
    {
        $folder = app(PersonalFiles::class)->folder(Auth::user(), $this->folderId);
        if ($folder !== null) {
            Gate::authorize('view', $folder);
        }

        return $folder;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function listing(): array
    {
        $files = app(PersonalFiles::class);
        $user = Auth::user();
        $storage = UserStorageQuota::summaryFor($user->id);

        return [
            'breadcrumbs' => $files->breadcrumbs($this->folder),
            'folders' => $files->folders($user, $this->folder),
            'entries' => $files->files($user, $this->folder),
            'pending' => $files->pendingCount($user),
            'summaries' => $files->summaries($user),
            'enabled' => (bool) config('nas.uploads_enabled'),
            'maxBytes' => (int) config('nas.upload_max_bytes'),
            'used' => $storage['used'],
            'limit' => $storage['limit'],
        ];
    }

    /** files.js finished uploading: show the new files without reloading. */
    #[On('files-uploaded')]
    public function refreshListing(): void
    {
        unset($this->listing);
    }

    public function createFolder(PersonalFiles $files): void
    {
        $this->throttle('files');
        $this->validate(['newFolder' => ['required', 'string', 'max:255']], attributes: ['newFolder' => 'nombre']);
        $folder = DomainErrors::guard('newFolder', fn () => $files->createFolder(Auth::user(), $this->folderId, $this->newFolder));
        $this->reset('newFolder');
        unset($this->listing);
        $this->toast('Carpeta «'.$folder->name.'» creada');
    }

    public function renameFolder(PersonalFiles $files): void
    {
        $this->throttle('files');
        Gate::authorize('update', $this->folder);
        $this->validate(['folderName' => ['required', 'string', 'max:255']], attributes: ['folderName' => 'nombre']);
        $folder = DomainErrors::guard('folderName', fn () => $files->renameFolder(Auth::user(), (int) $this->folderId, $this->folderName));
        $this->folderName = $folder->name;
        unset($this->folder, $this->listing);
        $this->toast('Carpeta renombrada');
    }

    public function deleteFolder(PersonalFiles $files): void
    {
        $this->throttle('files');
        Gate::authorize('update', $this->folder);
        $parent = $this->folder->parent_id;
        DomainErrors::guard('folder_delete', fn () => $files->deleteEmptyFolder(Auth::user(), (int) $this->folderId));
        session()->flash('toast', 'Carpeta eliminada');
        $this->redirect($parent === null ? route('files.index') : route('files.folder', $parent), navigate: true);
    }

    public function trash(int $fileId, PersonalFiles $files): void
    {
        $this->throttle('files');
        Gate::authorize('update', $files->file(Auth::user(), $fileId));
        $file = DomainErrors::guard('file_trash', fn () => $files->trash(Auth::user(), $fileId));
        $this->selectedFiles = array_values(array_diff($this->selectedFiles, [$fileId, (string) $fileId]));
        unset($this->listing);
        $this->toast('«'.$file->name.'» está en la papelera. Puedes restaurarlo durante '.(int) config('nas.trash_days').' días');
    }

    public function export(string $scope, FileExports $exports): void
    {
        $this->throttle('file-exports');
        $data = $this->validate([
            'selectedFiles' => ['array', 'max:'.FileExports::MAX_ITEMS],
            'selectedFiles.*' => ['integer'],
            'selectedFolders' => ['array', 'max:500'],
            'selectedFolders.*' => ['integer'],
        ]);
        abort_unless(in_array($scope, ['selection', 'folder', 'all'], true), 422);
        $export = DomainErrors::guard('export', fn () => $exports->request(
            Auth::user(),
            $scope,
            $this->folderId,
            array_values(array_map('intval', $data['selectedFiles'])),
            array_values(array_map('intval', $data['selectedFolders'])),
        ));
        session()->flash('toast', $export->state === 'ready' ? '«'.$export->name.'» está listo para descargar' : '«'.$export->name.'» se está preparando');
        $this->redirectRoute('files.exports.index', navigate: true);
    }
}; ?>

<div>
    @php
        $l = $this->listing;
        $folder = $this->folder;
        $fmt = fn (?int $bytes): string => \App\Support\Bytes::format($bytes);
        $describe = fn (array $summary): string => $summary['items'].' '.($summary['items'] === 1 ? 'elemento' : 'elementos').' · '.$fmt($summary['bytes']);
        $icon = fn (?string $mime): string => match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'audio/') => 'music',
            default => 'document',
        };
        $crumbs = [['label' => 'Mis archivos', 'href' => route('files.index')]];
        foreach ($l['breadcrumbs'] as $crumb) {
            $crumbs[] = ['label' => $crumb->name, 'href' => route('files.folder', $crumb)];
        }
        $empty = $l['folders']->isEmpty() && $l['entries']->isEmpty();
    @endphp

    <x-ui::breadcrumbs :items="$crumbs" />
    <x-ui::page-header :title="$folder->name ?? 'Mis archivos'" :description="'En esta carpeta: '.$describe($l['summaries'][$folder->id ?? 0] ?? ['items' => 0, 'bytes' => 0])">
        <x-slot:actions>
            <x-ui::button variant="ghost" icon="trash" :href="route('files.trash')" wire:navigate>Papelera</x-ui::button>
            <x-ui::button variant="ghost" icon="archive" :href="route('files.exports.index')" wire:navigate>Descargas ZIP</x-ui::button>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::stack gap="lg">
        @if (! $l['enabled'])
            <x-ui::notice tone="info">Los archivos privados todavía no están activados en este servidor. El responsable técnico debe preparar una ubicación privada (docs/STORAGE.md). Mientras tanto puedes ver lo que ya tengas guardado.</x-ui::notice>
        @elseif ($l['limit'] === 0)
            <x-ui::notice tone="info">Aún no tienes espacio asignado. Pide a un administrador que te asigne espacio en Almacenamiento.</x-ui::notice>
        @endif

        <x-ui::toolbar>
            @if ($l['enabled'] && $l['limit'] > 0)
                <x-ui::uploader :store-url="route('files.store')" :upload-url="url('uploads')" :folder="$folder->id ?? null" :max-bytes="$l['maxBytes']"
                    :hint="'Hasta '.$fmt($l['maxBytes']).' por archivo. Documentos, PDF, imágenes, texto y audio.'" />
            @endif
            <x-ui::form wire:submit="createFolder">
                <x-ui::field name="newFolder" label="Nueva carpeta" wire:model="newFolder" maxlength="120" required placeholder="Ej. Facturas 2026" />
                <x-ui::button type="submit" variant="secondary" icon="plus">Crear carpeta</x-ui::button>
            </x-ui::form>
            @if ($l['limit'] > 0)
                <x-slot:end>
                    <x-ui::meter :value="\App\Support\Bytes::percent($l['used'], $l['limit'])" label="Espacio usado">{{ $fmt($l['used']) }} de {{ $fmt($l['limit']) }}</x-ui::meter>
                </x-slot:end>
            @endif
        </x-ui::toolbar>

        @if ($l['pending'] > 0)
            <x-ui::text tone="muted">{{ $l['pending'] }} {{ $l['pending'] === 1 ? 'subida sin terminar' : 'subidas sin terminar' }}. Si cerraste la página a mitad, el espacio se libera solo al cabo de unos 30–40 minutos.</x-ui::text>
        @endif
        @foreach (['folder_delete', 'file_trash', 'export'] as $field)
            @error($field)<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        @endforeach

        <x-ui::stack gap="sm">
            @if (! $empty)
                <x-ui::toolbar>
                    <x-ui::button variant="secondary" size="sm" icon="archive" wire:click="export('selection')">Descargar selección en ZIP</x-ui::button>
                    <x-ui::button variant="ghost" size="sm" wire:click="export('{{ $folder === null ? 'all' : 'folder' }}')">{{ $folder === null ? 'Exportar todo en ZIP' : 'Descargar esta carpeta en ZIP' }}</x-ui::button>
                </x-ui::toolbar>
            @endif
            <x-ui::table label="Contenido de la carpeta">
                <x-slot:head>
                    <tr><th><x-ui::sr>Seleccionar</x-ui::sr></th><th>Nombre</th><th>Contenido</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
                </x-slot:head>
                @foreach ($l['folders'] as $child)
                    <tr wire:key="folder-{{ $child->id }}">
                        <x-ui::td check><input type="checkbox" value="{{ $child->id }}" wire:model="selectedFolders" aria-label="Seleccionar carpeta {{ $child->name }}"></x-ui::td>
                        <x-ui::td><x-ui::entry :href="route('files.folder', $child)" icon="folder" spa>{{ $child->name }}</x-ui::entry></x-ui::td>
                        <x-ui::td label="Contenido">{{ $describe($l['summaries'][$child->id] ?? ['items' => 0, 'bytes' => 0]) }}</x-ui::td>
                        <x-ui::td align="end"></x-ui::td>
                    </tr>
                @endforeach
                @foreach ($l['entries'] as $entry)
                    <tr wire:key="file-{{ $entry->id }}">
                        <x-ui::td check><input type="checkbox" value="{{ $entry->id }}" wire:model="selectedFiles" aria-label="Seleccionar {{ $entry->name }}"></x-ui::td>
                        <x-ui::td><x-ui::entry :href="route('files.show', $entry)" :icon="$icon($entry->mime)">{{ $entry->name }}</x-ui::entry></x-ui::td>
                        <x-ui::td label="Contenido">{{ $fmt((int) $entry->size) }}</x-ui::td>
                        <x-ui::td align="end">
                            <x-ui::button variant="ghost" size="sm" icon="download" :href="route('files.content', ['fileId' => $entry->id, 'download' => 1])" :aria-label="'Descargar '.$entry->name">Descargar</x-ui::button>
                            <x-ui::button variant="ghost" size="sm" icon="trash" wire:click="trash({{ $entry->id }})" :aria-label="'Mover a la papelera '.$entry->name">Papelera</x-ui::button>
                        </x-ui::td>
                    </tr>
                @endforeach
                @if ($empty)
                    <x-ui::table-empty colspan="4">Esta carpeta está vacía.</x-ui::table-empty>
                @endif
            </x-ui::table>
        </x-ui::stack>

        @if ($folder !== null)
            <x-ui::panel aria-labelledby="folder-settings">
                <x-ui::stack gap="md">
                    <x-ui::heading id="folder-settings">Opciones de la carpeta</x-ui::heading>
                    <x-ui::form wire:submit="renameFolder">
                        <x-ui::field name="folderName" label="Nombre de la carpeta" wire:model="folderName" maxlength="120" required />
                        <x-ui::button type="submit" variant="secondary">Guardar nombre</x-ui::button>
                    </x-ui::form>
                    <x-ui::text tone="muted">Solo se pueden eliminar carpetas vacías.</x-ui::text>
                    <x-ui::button variant="danger" icon="trash" wire:click="deleteFolder">Eliminar carpeta</x-ui::button>
                </x-ui::stack>
            </x-ui::panel>
        @endif
    </x-ui::stack>
</div>
