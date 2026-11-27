<?php

use App\Livewire\Concerns\ManagesOwnFiles;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\Publication;
use App\Models\UserFile;
use App\Services\Files\FilePreview;
use App\Services\Files\PersonalFiles;
use App\Services\Files\Publications;
use App\Support\DomainErrors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Archivo')] class extends Component {
    use ManagesOwnFiles, NotifiesInterface, ThrottlesActions;

    #[Locked]
    public int $fileId;

    public string $name = '';

    public function mount(int $fileId): void
    {
        $this->fileId = $fileId;
        $this->name = $this->file->name;
    }

    /** Owner-scoped lookup on every request: someone else's file is "not found". */
    #[Computed]
    public function file(): UserFile
    {
        $file = app(PersonalFiles::class)->file(Auth::user(), $this->fileId);
        Gate::authorize('view', $file);

        return $file;
    }

    /** The current file when this one is an older version. */
    #[Computed]
    public function current(): ?UserFile
    {
        return $this->file->version_of_id === null ? null : app(PersonalFiles::class)->file(Auth::user(), $this->file->version_of_id);
    }

    /** @return Collection<int, UserFile> */
    #[Computed]
    public function versions(): Collection
    {
        return $this->file->isCurrent() ? app(PersonalFiles::class)->versions(Auth::user(), $this->file) : collect();
    }

    /** @return array{kind: string, mime: string, size: int, text: ?string, html: ?string, truncated: bool, error: ?string} */
    #[Computed]
    public function preview(): array
    {
        return app(FilePreview::class)->build(Auth::user(), $this->file);
    }

    /** @return list<array{label: string, href: string}> */
    #[Computed]
    public function crumbs(): array
    {
        $files = app(PersonalFiles::class);
        $crumbs = [['label' => 'Mis archivos', 'href' => route('files.index')]];
        foreach ($files->breadcrumbs($files->folder(Auth::user(), ($this->current ?? $this->file)->folder_id)) as $folder) {
            $crumbs[] = ['label' => $folder->name, 'href' => route('files.folder', $folder)];
        }
        $crumbs[] = ['label' => $this->file->name, 'href' => route('files.show', $this->file)];

        return $crumbs;
    }

    public function rename(PersonalFiles $files): void
    {
        $this->throttle('files');
        Gate::authorize('update', $this->file);
        $this->validate(['name' => ['required', 'string', 'max:255']], attributes: ['name' => 'nombre']);
        $file = DomainErrors::guard('name', fn () => $files->renameFile(Auth::user(), $this->fileId, $this->name));
        $this->name = $file->name;
        $this->changed('Nombre guardado');
    }

    public function trash(): void
    {
        $file = $this->trashOwnFile($this->fileId);
        session()->flash('toast', $this->trashedMessage($file));
        $this->redirect($file->original_folder_id === null ? route('files.index') : route('files.folder', $file->original_folder_id), navigate: true);
    }

    public function restore(): void
    {
        $file = $this->restoreOwnFile($this->fileId);
        $this->name = $file->name;
        $this->changed('Archivo restaurado como «'.$file->name.'»');
    }

    /** Makes a version current again: this file when it is a version, or one from the list. */
    public function promote(?int $versionId = null): void
    {
        $file = $this->promoteOwnFile($versionId ?? $this->fileId);
        if ($file->id !== $this->fileId) {
            session()->flash('toast', 'Versión restaurada. La anterior queda guardada como versión');
            $this->redirectRoute('files.show', $file);

            return;
        }
        $this->changed('Versión restaurada. La anterior queda guardada como versión');
    }

    /** Deletes for good: this file (from the trash or as a version) or a version from the list. */
    public function purge(?int $targetId = null): void
    {
        $id = $targetId ?? $this->fileId;
        $current = $this->purgeOwnFile($id);
        if ($id !== $this->fileId) {
            $this->changed('Versión borrada. El espacio ya está disponible');

            return;
        }
        session()->flash('toast', 'Borrado definitivamente. El espacio ya está disponible');
        $this->redirect($current === null ? route('files.trash') : route('files.show', $current));
    }

    /** Proposal for everyone (S04b): only for files in a connected folder; an administrator decides. */
    #[Computed]
    public function publication(): ?Publication
    {
        return app(Publications::class)->forFile($this->file);
    }

    public function proposePublication(Publications $publications): void
    {
        $this->throttle('files');
        Gate::authorize('update', $this->file);
        $publications->request(Auth::user(), $this->fileId);
        $this->changed('Propuesto para todos. Un administrador decidirá si se publica');
    }

    public function cancelPublication(Publications $publications): void
    {
        $this->throttle('files');
        $publications->cancel(Auth::user(), (int) $this->publication?->id);
        $this->changed('Propuesta cancelada');
    }

    public function askWithdrawal(Publications $publications): void
    {
        $this->throttle('files');
        $publications->askWithdrawal(Auth::user(), (int) $this->publication?->id);
        $this->changed('Se ha pedido a un administrador que lo retire');
    }

    private function changed(string $message): void
    {
        unset($this->file, $this->current, $this->versions, $this->preview, $this->crumbs, $this->publication);
        $this->toast($message);
    }
}; ?>

<div>
    @php
        $file = $this->file;
        $p = $this->preview;
        $fmt = fn (?int $bytes): string => \App\Support\Bytes::format($bytes);
        $content = route('files.content', ['fileId' => $file->id]);
    @endphp

    <x-ui::breadcrumbs :items="$this->crumbs" />
    @php($folderCrumb = $this->crumbs[count($this->crumbs) - 2])
    <x-ui::page-header :title="$file->name" :back="$file->trashed_at !== null ? route('files.trash') : $folderCrumb['href']" :back-label="$file->trashed_at !== null ? 'Papelera' : $folderCrumb['label']" :description="$p['error'] === null ? $fmt($p['size']).' · '.$p['mime'] : null">
        @if ($p['error'] === null)
            <x-slot:actions>
                @if ($file->isCurrent() && in_array($p['mime'], \App\Support\Finance\ReceiptReader::TYPES, true))
                    @can('use-finances')<x-ui::button variant="secondary" :href="route('finances.index', ['receipt' => $file->id])">Registrar en Mis finanzas</x-ui::button>@endcan
                @endif
                <x-ui::button icon="download" :href="route('files.content', ['fileId' => $file->id, 'download' => 1])">Descargar</x-ui::button>
                @if ($p['kind'] !== 'none')
                    <x-ui::button variant="ghost" :href="$content" target="_blank" rel="noopener">Abrir en pestaña nueva</x-ui::button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui::page-header>

    <x-ui::stack gap="lg">
        @foreach (['file_trash', 'file_purge', 'file_version'] as $field)
            @error($field)<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        @endforeach

        @error('publication')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        @php($publication = $this->publication)
        @if ($publication?->isPublished())
            <x-ui::notice tone="info">
                Publicado para todos el {{ $publication->decided_at?->format('d/m/Y') }}: lo ven quienes tienen el servicio y ya no cuenta en tu espacio. Solo un administrador puede retirarlo.
                @if ($publication->withdrawal_requested_at === null)
                    <x-ui::link wire:click="askWithdrawal">Pedir que se retire</x-ui::link>
                @else
                    Has pedido que se retire.
                @endif
            </x-ui::notice>
        @elseif ($publication !== null)
            <x-ui::notice tone="info">
                Propuesto para todos el {{ $publication->requested_at->format('d/m/Y') }}. Mientras un administrador decide, no se puede renombrar ni mover a la papelera.
                <x-ui::link wire:click="cancelPublication">Cancelar la propuesta</x-ui::link>
            </x-ui::notice>
        @elseif ($file->isCurrent() && $file->upload()->value('media_link') !== null)
            <x-ui::notice tone="info">
                Está en una carpeta conectada: puedes proponer que lo vean todos. Si un administrador lo publica, dejará de contar en tu espacio y no podrás borrarlo tú.
                <x-ui::link wire:click="proposePublication">Proponer para todos</x-ui::link>
            </x-ui::notice>
        @endif

        @if ($file->trashed_at !== null)
            <x-ui::notice tone="warn">
                En la papelera desde el {{ $file->trashed_at->format('d/m/Y') }}. Se borrará definitivamente a los {{ (int) config('nas.trash_days') }} días.
                <x-ui::link wire:click="restore">Restaurar</x-ui::link>
            </x-ui::notice>
        @elseif ($this->current !== null)
            <x-ui::notice tone="info">
                Versión anterior de <x-ui::link :href="route('files.show', $this->current)">{{ $this->current->name }}</x-ui::link>, guardada el {{ $file->superseded_at?->format('d/m/Y H:i') }}.
                <x-ui::link wire:click="promote">Restaurar esta versión</x-ui::link>
            </x-ui::notice>
        @endif

        @if ($p['error'] !== null)
            <x-ui::notice tone="bad">{{ $p['error'] }} Avisa al responsable técnico si el problema continúa.</x-ui::notice>
        @elseif (in_array($p['kind'], ['image', 'video', 'audio', 'pdf'], true))
            <x-ui::preview :kind="$p['kind']" :src="$content" :title="$file->name" />
        @elseif ($p['html'] !== null)
            <x-ui::preview kind="rich"><x-ui::prose>{!! $p['html'] !!}</x-ui::prose></x-ui::preview>
        @elseif ($p['text'] !== null)
            <x-ui::preview kind="text" :note="$p['truncated'] ? 'Vista previa limitada a '.$fmt((int) config('nas.preview_text_max_bytes')).'. Descarga el archivo para verlo completo.' : null">{{ $p['text'] }}</x-ui::preview>
        @else
            <x-ui::preview kind="none">Este tipo de archivo no tiene vista previa. Puedes descargarlo y abrirlo con tu programa habitual.</x-ui::preview>
        @endif

        @if ($this->versions->isNotEmpty())
            <x-ui::stack gap="sm" aria-labelledby="versions">
                <x-ui::heading id="versions">Versiones anteriores</x-ui::heading>
                <x-ui::text tone="muted">Se guardan las {{ (int) config('nas.max_versions') }} más recientes al subir un archivo con el mismo nombre. Ocupan espacio de tu cuota.</x-ui::text>
                <x-ui::table label="Versiones anteriores">
                    <x-slot:head><tr><th>Guardada el</th><th>Tamaño</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                    @foreach ($this->versions as $version)
                        <tr wire:key="version-{{ $version->id }}">
                            <x-ui::td><x-ui::entry :href="route('files.show', $version)" icon="clock">{{ $version->superseded_at?->format('d/m/Y H:i') }}</x-ui::entry></x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ $fmt((int) $version->size) }}</x-ui::td>
                            <x-ui::td align="end">
                                <x-ui::button variant="ghost" size="sm" icon="download" label="Descargar esta versión" :href="route('files.content', ['fileId' => $version->id, 'download' => 1])" />
                                <x-ui::button variant="ghost" size="sm" icon="restore" label="Restaurar esta versión" wire:click="promote({{ $version->id }})" />
                                <x-ui::confirm title="¿Borrar esta versión?">
                                    <x-slot:trigger><x-ui::button variant="ghost" size="sm" icon="trash" label="Borrar esta versión" /></x-slot:trigger>
                                    Se borrará definitivamente la versión del {{ $version->superseded_at?->format('d/m/Y H:i') }}. No se puede deshacer.
                                    <x-slot:action><x-ui::button variant="danger" wire:click="purge({{ $version->id }})">Borrar versión</x-ui::button></x-slot:action>
                                </x-ui::confirm>
                            </x-ui::td>
                        </tr>
                    @endforeach
                </x-ui::table>
            </x-ui::stack>
        @endif

        @if ($file->isCurrent())
            <x-ui::panel aria-labelledby="file-options">
                <x-ui::stack gap="md">
                    <x-ui::heading id="file-options">Opciones del archivo</x-ui::heading>
                    <x-ui::form wire:submit="rename" inline>
                        <x-ui::field name="name" label="Nombre" wire:model="name" maxlength="200" required />
                        <x-ui::button type="submit" variant="secondary">Guardar nombre</x-ui::button>
                    </x-ui::form>
                    <x-ui::separator />
                    <x-ui::text tone="muted">Irá a la papelera; puedes restaurarlo durante {{ (int) config('nas.trash_days') }} días.</x-ui::text>
                    <x-ui::button variant="danger" icon="trash" wire:click="trash">Mover a la papelera</x-ui::button>
                </x-ui::stack>
            </x-ui::panel>
        @elseif ($file->trashed_at !== null)
            <x-ui::confirm title="¿Borrar definitivamente?">
                <x-slot:trigger><x-ui::button variant="danger" icon="trash">Borrar definitivamente</x-ui::button></x-slot:trigger>
                Se borrará «{{ $file->name }}» con todas sus versiones. No se puede deshacer.
                <x-slot:action><x-ui::button variant="danger" wire:click="purge">Borrar definitivamente</x-ui::button></x-slot:action>
            </x-ui::confirm>
        @endif
    </x-ui::stack>
</div>
