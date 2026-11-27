<?php

use App\Enums\FileKind;
use App\Livewire\Concerns\ManagesOwnFiles;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\AssistantSetting;
use App\Models\Folder;
use App\Models\UserStorageQuota;
use App\Services\Assistant\AssistantAccess;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\FileExports;
use App\Services\Files\PersonalFiles;
use App\Services\Files\Publications;
use App\Support\DomainErrors;
use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Mis archivos')] class extends Component {
    use ManagesOwnFiles, NotifiesInterface, ThrottlesActions;

    #[Locked]
    public ?int $folderId = null;

    public string $newFolder = '';

    public string $folderName = '';

    /** Search in the whole tree (empty: this folder only). */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tipo', except: '')]
    public string $kind = '';

    #[Url(as: 'orden', except: 'name')]
    public string $sort = 'name';

    #[Url(as: 'dir', except: 'asc')]
    public string $direction = 'asc';

    /** @var list<int|string> */
    public array $selectedFiles = [];

    /** @var list<int|string> */
    public array $selectedFolders = [];

    public function mount(?int $folderId = null): void
    {
        $this->folderId = $folderId;
        $this->folderName = $this->folder->name ?? '';
        // The person's folders for Música, Libros… appear as soon as the service is available.
        if ($folderId === null) {
            app(ConnectedFolders::class)->ensure(Auth::user());
        }
    }

    /**
     * Service this folder (or its top-level ancestor) feeds, for the notice and icons.
     *
     * @return array{service: string, name: string, section: string|null, icon: string, library: string, mimes: list<string>}|null
     */
    #[Computed]
    public function connection(): ?array
    {
        $connection = app(ConnectedFolders::class)->of($this->folder);

        return $connection === null ? null : ConnectedFolders::definition($connection);
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
            ...$this->browse($files),
            'searching' => trim($this->search) !== '',
            'paths' => trim($this->search) !== '' ? $files->folderPaths($user) : [],
            'view' => $user->preferences()->filesView(),
            'pending' => $files->pendingCount($user),
            'summaries' => $files->summaries($user),
            'enabled' => (bool) config('nas.uploads_enabled'),
            'maxBytes' => (int) config('nas.upload_max_bytes'),
            'used' => $storage['used'],
            'limit' => $storage['limit'],
        ];
    }

    /** @return array{folders: \Illuminate\Support\Collection<int, Folder>, entries: \Illuminate\Support\Collection<int, \App\Models\UserFile>} */
    private function browse(PersonalFiles $files): array
    {
        $kind = FileKind::tryFrom($this->kind)?->value ?? '';
        $sort = array_key_exists($this->sort, PersonalFiles::SORTS) ? $this->sort : 'name';
        $found = $files->browse(Auth::user(), $this->folder, mb_substr($this->search, 0, 100), $kind, $sort, $this->direction);

        return ['folders' => $found['folders'], 'entries' => $found['files']];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'sort', 'direction'], true)) {
            unset($this->listing);
        }
    }

    public function toggleDirection(): void
    {
        $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        unset($this->listing);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'kind', 'sort', 'direction');
        unset($this->listing);
    }

    /** Lista, Detalles or Galería: remembered for the person, like a desktop explorer. */
    public function setView(string $view): void
    {
        $this->throttle('files');
        abort_unless(in_array($view, UserPreferences::OPTIONS['files_view'], true), 422);
        $user = Auth::user();
        $user->preferences = $user->preferences()->with('files_view', $view)->toArray();
        $user->save();
        unset($this->listing);
    }

    /** Files just uploaded on this page, highlighted in the listing (display only). */
    #[Locked]
    public array $fresh = [];

    /**
     * files.js finished some uploads: show them without reloading and mark them as new.
     *
     * @param  array<mixed>  $ids
     */
    #[On('files-uploaded')]
    public function refreshListing(array $ids = []): void
    {
        $this->fresh = array_slice(array_values(array_filter($ids, 'is_int')), 0, 200);
        unset($this->listing);
    }

    public function createFolder(PersonalFiles $files): void
    {
        $this->throttle('files');
        $this->validate(['newFolder' => ['required', 'string', 'max:255']], attributes: ['newFolder' => 'nombre']);
        $folder = DomainErrors::guard('newFolder', fn () => $files->createFolder(Auth::user(), $this->folderId, $this->newFolder));
        $this->reset('newFolder');
        unset($this->listing);
        $this->dispatch('ui-dialog-close', name: 'new-folder');
        $this->toast('Carpeta «'.$folder->name.'» creada');
    }

    public function setAssistantExcluded(AssistantAccess $access, bool $excluded): void
    {
        // Each change rebuilds the person's search index with the provider: never in a loop.
        $this->throttle('files');
        $access->setFolderExcluded(Auth::user(), (int) $this->folderId, $excluded);
        unset($this->folder);
        $this->toast('Preferencia de privacidad guardada');
    }

    public function renameFolder(PersonalFiles $files): void
    {
        $this->throttle('files');
        Gate::authorize('update', $this->folder);
        $this->validate(['folderName' => ['required', 'string', 'max:255']], attributes: ['folderName' => 'nombre']);
        $folder = DomainErrors::guard('folderName', fn () => $files->renameFolder(Auth::user(), (int) $this->folderId, $this->folderName));
        $this->folderName = $folder->name;
        unset($this->folder, $this->listing);
        $this->dispatch('ui-dialog-close', name: 'folder-options');
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

    public function trash(int $fileId): void
    {
        $file = $this->trashOwnFile($fileId);
        $this->selectedFiles = array_values(array_diff($this->selectedFiles, [$fileId, (string) $fileId]));
        unset($this->listing);
        $this->toast($this->trashedMessage($file));
    }

    /** Bulk move to trash from the selection bar (files only; folders are not trashed). */
    public function trashSelected(): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($this->selectedFiles, 'is_numeric'))));
        abort_if($ids === [] || count($ids) > 500, 422);
        $moved = $this->trashOwnFiles($ids);
        $this->selectedFiles = [];
        unset($this->listing);
        $this->toast($moved === 1 ? '1 archivo movido a la papelera' : $moved.' archivos movidos a la papelera');
    }

    /**
     * Selected files to the common fund, as the person's role allows (docs/STORAGE.md §
     * Publicación para todos): administrators publish them after a recent password
     * confirmation, like on Administración → Para todos; anyone else proposes them.
     */
    public function shareSelected(Publications $publications): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($this->selectedFiles, 'is_numeric'))));
        abort_if($ids === [] || count($ids) > 500, 422);
        $user = Auth::user();
        $this->throttle('files');
        $deciding = Gate::allows('publish-content');
        if ($deciding) {
            $this->throttle('admin');
            if (time() - (int) session('auth.password_confirmed_at', 0) > (int) config('auth.password_timeout')) {
                redirect()->setIntendedUrl(url()->previous());
                $this->redirectRoute('password.confirm');

                return;
            }
        }
        $files = app(PersonalFiles::class);
        foreach ($ids as $id) {
            Gate::authorize('update', $files->file($user, $id));
        }
        $result = DomainErrors::guard('publication', fn () => $publications->share($user, $ids));
        $this->selectedFiles = [];
        unset($this->listing);
        $count = fn (int $n, string $one, string $many): string => $n === 1 ? '1 archivo '.$one : $n.' archivos '.$many;
        $this->toast(match (true) {
            $result['published'] > 0 => $count($result['published'], 'publicado', 'publicados').' para todos',
            $result['requested'] > 0 => $count($result['requested'], 'propuesto', 'propuestos').' para todos. Un administrador decidirá',
            default => 'Ya estaban propuestos o publicados para todos',
        });
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
        $crumbs = [['label' => 'Mis archivos', 'href' => route('files.index')]];
        foreach ($l['breadcrumbs'] as $crumb) {
            $crumbs[] = ['label' => $crumb->name, 'href' => route('files.folder', $crumb)];
        }
        $empty = $l['folders']->isEmpty() && $l['entries']->isEmpty();
    @endphp

    @php
        $trail = array_values($l['breadcrumbs']);
        $parent = count($trail) > 1 ? $trail[count($trail) - 2] : null;
        $summary = $describe($l['summaries'][$folder->id ?? 0] ?? ['items' => 0, 'bytes' => 0]);
        $usage = $l['limit'] > 0 ? ' · Usas '.$fmt($l['used']).' de '.$fmt($l['limit']) : '';
        $selected = '$wire.selectedFiles.length + $wire.selectedFolders.length';
    @endphp
    <x-ui::breadcrumbs :items="$crumbs" />
    <x-ui::page-header :title="$folder->name ?? 'Mis archivos'" :description="$summary.$usage"
        :back="$folder === null ? null : ($parent === null ? route('files.index') : route('files.folder', $parent))"
        :back-label="$folder === null ? '' : ($parent->name ?? 'Mis archivos')">
        <x-slot:actions>
            @if ($folder !== null && ! $folder->isConnected())
                <x-ui::button variant="ghost" icon="settings" label="Opciones de la carpeta" x-on:click="$dispatch('ui-dialog-open', 'folder-options')" />
            @endif
            @unless ($empty)
                <x-ui::button variant="ghost" icon="archive" :label="$folder === null ? 'Descargar todo en ZIP' : 'Descargar esta carpeta en ZIP'" wire:click="export('{{ $folder === null ? 'all' : 'folder' }}')" />
            @endunless
            <x-ui::button variant="secondary" icon="plus" x-on:click="$dispatch('ui-dialog-open', 'new-folder')">Nueva carpeta</x-ui::button>
            @if ($l['enabled'] && $l['limit'] > 0)
                <x-ui::uploader :store-url="route('files.store')" :upload-url="url('uploads')" :folder="$folder->id ?? null" :max-bytes="$l['maxBytes']"
                    :hint="'Hasta '.$fmt($l['maxBytes']).' por archivo.'" />
            @endif
        </x-slot:actions>
    </x-ui::page-header>
    @if ($folder !== null && AssistantSetting::current()->content_allowed)
        <x-ui::button variant="ghost" wire:click="setAssistantExcluded({{ $folder->assistant_excluded ? 'false' : 'true' }})">
            {{ $folder->assistant_excluded ? 'Quitar exclusión de contenido en esta carpeta' : 'Excluir contenido de esta carpeta y subcarpetas del asistente' }}
        </x-ui::button>
    @endif
    @if ($this->connection)
        @php
            $serviceName = config('portal.services.'.$this->connection['service'].'.name');
        @endphp
        <x-ui::notice>
            Lo que subas aquí aparece en {{ $serviceName }}, en «{{ $this->connection['library'] }}», y solo lo ves tú. Para que {{ $serviceName }} pueda leerlos, los archivos de esta carpeta no se cifran.
        </x-ui::notice>
    @endif

    <x-ui::stack gap="lg">
        <x-pages::files.tabs active="files" />

        @if (! $l['enabled'])
            <x-ui::notice tone="info">Los archivos privados todavía no están activados en este servidor. El responsable técnico debe preparar una ubicación privada (docs/STORAGE.md). Mientras tanto puedes ver lo que ya tengas guardado.</x-ui::notice>
        @elseif ($l['limit'] === 0)
            <x-ui::notice tone="info">Aún no tienes espacio asignado. Pide a un administrador que te asigne espacio en Almacenamiento.</x-ui::notice>
        @elseif (\App\Support\Bytes::percent($l['used'], $l['limit']) >= 90)
            <x-ui::notice tone="warn">Te queda poco espacio: usas {{ $fmt($l['used']) }} de {{ $fmt($l['limit']) }}. Vacía la papelera o pide más espacio a un administrador.</x-ui::notice>
        @endif

        @if ($l['pending'] > 0)
            <x-ui::text tone="muted">{{ $l['pending'] }} {{ $l['pending'] === 1 ? 'subida sin terminar' : 'subidas sin terminar' }}. Si cerraste la página a mitad, el espacio se libera solo al cabo de unos 30–40 minutos.</x-ui::text>
        @endif
        @foreach (['folder_delete', 'file_trash', 'export', 'publication'] as $field)
            @error($field)<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        @endforeach

        <x-ui::stack gap="sm">
            <x-ui::command-stack :selecting="$selected.' > 0'">
            <x-ui::selection-bar :show="$selected.' > 0'">
                <span class="ui-selection-count" x-text="(n => n === 1 ? '1 elemento seleccionado' : n + ' elementos seleccionados')({{ $selected }})"></span>
                <x-ui::button variant="secondary" size="sm" icon="archive" wire:click="export('selection')">Descargar en ZIP</x-ui::button>
                @if ($this->connection !== null || $l['searching'])
                    @php($deciding = Gate::allows('publish-content'))
                    <x-ui::confirm :title="$deciding ? '¿Publicar para todos los archivos seleccionados?' : '¿Proponer para todos los archivos seleccionados?'">
                        <x-slot:trigger>
                            <x-ui::button variant="secondary" size="sm" icon="share" x-bind:disabled="$wire.selectedFiles.length === 0" title="Solo archivos de carpetas conectadas (Música, Libros, Cómics, Vídeos…); las carpetas seleccionadas no se envían">{{ $deciding ? 'Publicar para todos' : 'Proponer para todos' }}</x-ui::button>
                        </x-slot:trigger>
                        @if ($deciding)
                            Los verán todas las personas con acceso al servicio. Su espacio pasa al fondo común y dejarán de contar en el tuyo. Solo se publican archivos de carpetas conectadas: si alguno no lo está, no se publica ninguno.
                        @else
                            Un administrador decidirá si los publica. Mientras tanto no podrás renombrarlos ni moverlos a la papelera. Si los publica, los verán todas las personas con acceso al servicio, dejarán de contar en tu espacio y no podrás borrarlos tú.
                        @endif
                        <x-slot:action><x-ui::button wire:click="shareSelected">{{ $deciding ? 'Publicar para todos' : 'Proponer para todos' }}</x-ui::button></x-slot:action>
                    </x-ui::confirm>
                @endif
                <x-ui::button variant="secondary" size="sm" icon="trash" wire:click="trashSelected" x-bind:disabled="$wire.selectedFiles.length === 0" title="Las carpetas no van a la papelera; solo los archivos seleccionados">Mover a la papelera</x-ui::button>
                <x-ui::button variant="ghost" size="sm" icon="close" label="Quitar la selección" x-on:click="$wire.selectedFiles = []; $wire.selectedFolders = []" />
            </x-ui::selection-bar>
            <x-ui::toolbar surface x-bind:inert="{{ $selected }} > 0">
                <x-ui::field name="q" type="search" :label="$folder === null ? 'Buscar en Mis archivos' : 'Buscar en Mis archivos (todas las carpetas)'" maxlength="100" placeholder="Nombre del archivo o carpeta" wire:model.live.debounce.300ms="search" />
                <x-ui::select name="tipo" label="Tipo" :options="['' => 'Todos'] + \App\Enums\FileKind::options()" wire:model.live="kind" />
                <x-ui::select name="orden" label="Ordenar por" :options="\App\Services\Files\PersonalFiles::SORTS" wire:model.live="sort" />
                <x-ui::button variant="ghost" :icon="$direction === 'asc' ? 'sort-asc' : 'sort-desc'" :label="$direction === 'asc' ? 'Orden ascendente (pulsa para invertir)' : 'Orden descendente (pulsa para invertir)'" wire:click="toggleDirection" />
                <x-slot:end>
                    <x-ui::segmented label="Vista">
                        <x-ui::button variant="ghost" size="sm" icon="view-details" :aria-pressed="$l['view'] === 'details' ? 'true' : 'false'" wire:click="setView('details')">Detalles</x-ui::button>
                        <x-ui::button variant="ghost" size="sm" icon="view-list" :aria-pressed="$l['view'] === 'list' ? 'true' : 'false'" wire:click="setView('list')">Lista</x-ui::button>
                        <x-ui::button variant="ghost" size="sm" icon="grid" :aria-pressed="$l['view'] === 'gallery' ? 'true' : 'false'" wire:click="setView('gallery')">Galería</x-ui::button>
                    </x-ui::segmented>
                </x-slot:end>
            </x-ui::toolbar>
            </x-ui::command-stack>

            @if ($l['searching'] || $kind !== '')
                <x-ui::row>
                    <x-ui::text tone="muted">{{ $l['folders']->count() + $l['entries']->count() }} {{ $l['folders']->count() + $l['entries']->count() === 1 ? 'resultado' : 'resultados' }}{{ $l['searching'] ? ' en todas tus carpetas' : '' }}</x-ui::text>
                    <x-ui::button variant="ghost" size="sm" icon="close" wire:click="clearFilters">Quitar filtros</x-ui::button>
                </x-ui::row>
            @endif

            @php
                $where = fn (?int $folderId): string => $folderId === null ? 'Mis archivos' : ($l['paths'][$folderId] ?? '');
                $emptyText = $l['searching'] || $kind !== '' ? 'Nada coincide con la búsqueda o el filtro.' : 'Esta carpeta está vacía. Sube archivos o arrástralos aquí.';
            @endphp

            <div class="ui-results" data-range-select>
            <x-ui::skeleton wire:loading.delay.flex wire:target="search,kind,sort,toggleDirection" />
            @if ($l['view'] === 'details')
                <x-ui::table label="Contenido de la carpeta" quiet>
                    <x-slot:head>
                        <tr><th><x-ui::sr>Seleccionar</x-ui::sr></th><th>Nombre</th>@if ($l['searching'])<th>Ubicación</th>@endif<th>Tipo</th><th>Subido</th><th>Tamaño</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
                    </x-slot:head>
                    @foreach ($l['folders'] as $child)
                        <tr wire:key="folder-{{ $child->id }}">
                            <x-ui::td check><input type="checkbox" value="{{ $child->id }}" wire:model="selectedFolders" aria-label="Seleccionar carpeta {{ $child->name }}"></x-ui::td>
                            <x-ui::td><x-ui::entry :href="route('files.folder', $child)" :icon="$child->isConnected() ? \App\Services\Files\ConnectedFolders::definition($child->media_link)['icon'] : 'folder'" spa>{{ $child->name }}</x-ui::entry></x-ui::td>
                            @if ($l['searching'])<x-ui::td label="Ubicación">{{ $where($child->parent_id) }}</x-ui::td>@endif
                            <x-ui::td label="Tipo" nowrap>{{ $child->isConnected() ? 'Conectada con '.config('portal.services.'.\App\Services\Files\ConnectedFolders::definition($child->media_link)['service'].'.name') : 'Carpeta' }}</x-ui::td>
                            <x-ui::td label="Subido" nowrap>{{ $child->created_at?->format('d/m/Y') }}</x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ $describe($l['summaries'][$child->id] ?? ['items' => 0, 'bytes' => 0]) }}</x-ui::td>
                            <x-ui::td align="end"></x-ui::td>
                        </tr>
                    @endforeach
                    @foreach ($l['entries'] as $entry)
                        @php($kindOf = \App\Enums\FileKind::fromMime($entry->mime))
                        <tr wire:key="file-{{ $entry->id }}" @if (in_array($entry->id, $this->fresh, true)) data-fresh @endif>
                            <x-ui::td check><input type="checkbox" value="{{ $entry->id }}" wire:model="selectedFiles" aria-label="Seleccionar {{ $entry->name }}"></x-ui::td>
                            <x-ui::td><x-ui::entry :href="route('files.show', $entry)" :icon="$kindOf->icon()">{{ $entry->name }}</x-ui::entry></x-ui::td>
                            @if ($l['searching'])<x-ui::td label="Ubicación">{{ $where($entry->folder_id) }}</x-ui::td>@endif
                            <x-ui::td label="Tipo" nowrap>{{ $kindOf->label() }}</x-ui::td>
                            <x-ui::td label="Subido" nowrap>{{ $entry->created_at?->format('d/m/Y H:i') }}</x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ $fmt((int) $entry->size) }}</x-ui::td>
                            <x-ui::td align="end">
                                <x-pages::files.entry-actions :entry="$entry" />
                            </x-ui::td>
                        </tr>
                    @endforeach
                    @if ($empty)
                        <x-ui::table-empty :colspan="$l['searching'] ? 7 : 6">{{ $emptyText }}</x-ui::table-empty>
                    @endif
                </x-ui::table>
            @elseif ($empty)
                <x-ui::empty-state icon="folder" title="Sin elementos">{{ $emptyText }}</x-ui::empty-state>
            @else
                <x-ui::file-grid :view="$l['view']" label="Contenido de la carpeta">
                    @foreach ($l['folders'] as $child)
                        <x-ui::file-card wire:key="folder-{{ $child->id }}" :href="route('files.folder', $child)" spa :icon="$child->isConnected() ? \App\Services\Files\ConnectedFolders::definition($child->media_link)['icon'] : 'folder'" :name="$child->name"
                            :meta="($l['searching'] ? $where($child->parent_id).' · ' : '').$describe($l['summaries'][$child->id] ?? ['items' => 0, 'bytes' => 0])">
                            <x-slot:select><input type="checkbox" value="{{ $child->id }}" wire:model="selectedFolders" aria-label="Seleccionar carpeta {{ $child->name }}"></x-slot:select>
                        </x-ui::file-card>
                    @endforeach
                    @foreach ($l['entries'] as $entry)
                        @php($kindOf = \App\Enums\FileKind::fromMime($entry->mime))
                        <x-ui::file-card wire:key="file-{{ $entry->id }}" :href="route('files.show', $entry)" :icon="$kindOf->icon()" :name="$entry->name"
                            :meta="($l['searching'] ? $where($entry->folder_id).' · ' : '').$fmt((int) $entry->size)"
                            :thumb="$kindOf->hasThumbnail() ? $kindOf->value : null" :src="route('files.content', ['fileId' => $entry->id])"
                            :fresh="in_array($entry->id, $this->fresh, true)">
                            <x-slot:select><input type="checkbox" value="{{ $entry->id }}" wire:model="selectedFiles" aria-label="Seleccionar {{ $entry->name }}"></x-slot:select>
                            <x-slot:actions>
                                <x-pages::files.entry-actions :entry="$entry" />
                            </x-slot:actions>
                        </x-ui::file-card>
                    @endforeach
                </x-ui::file-grid>
            @endif
            </div>
        </x-ui::stack>

    </x-ui::stack>

    <x-ui::dialog name="new-folder" title="Nueva carpeta" x-on:close="$wire.set('newFolder', '')">
        <x-ui::form wire:submit="createFolder">
            <x-ui::field name="newFolder" label="Nombre" wire:model="newFolder" maxlength="120" required placeholder="Ej. Facturas 2026" autofocus />
            <x-ui::actions>
                <x-ui::button variant="ghost" x-on:click="$dispatch('ui-dialog-close', 'new-folder')">Cancelar</x-ui::button>
                <x-ui::button type="submit" icon="plus">Crear carpeta</x-ui::button>
            </x-ui::actions>
        </x-ui::form>
    </x-ui::dialog>

    @if ($folder !== null)
        <x-ui::dialog name="folder-options" title="Opciones de la carpeta">
            <x-ui::stack gap="md">
                <x-ui::form wire:submit="renameFolder" inline>
                    <x-ui::field name="folderName" label="Nombre de la carpeta" wire:model="folderName" maxlength="120" required />
                    <x-ui::button type="submit" variant="secondary">Guardar nombre</x-ui::button>
                </x-ui::form>
                <x-ui::separator />
                <x-ui::text tone="muted">Solo se pueden eliminar carpetas vacías.</x-ui::text>
                <x-ui::confirm title="¿Eliminar esta carpeta vacía?">
                    <x-slot:trigger><x-ui::button variant="danger" icon="trash">Eliminar carpeta</x-ui::button></x-slot:trigger>
                    La carpeta se eliminará de Mis archivos.
                    <x-slot:action><x-ui::button variant="danger" wire:click="deleteFolder">Eliminar carpeta</x-ui::button></x-slot:action>
                </x-ui::confirm>
            </x-ui::stack>
        </x-ui::dialog>
    @endif
</div>
