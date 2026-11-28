<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\Downloads\StagedDownloads;
use App\Services\Files\PersonalFiles;
use App\Support\Bytes;
use App\Support\DomainErrors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Descargas (P04.3, docs/DOWNLOADS.md): what MeTube finished, waiting to be saved into the
 * person's Mis archivos (a normal upload: quota, type check, encryption) or discarded.
 */
new #[Title('Descargas por guardar')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    /** Folder of the person's Mis archivos ('' = the root). */
    public string $folder = '';

    public function mount(): void
    {
        Gate::authorize('manage-downloads');
    }

    /** @return list<array{id: string, name: string, folder: string, bytes: int, modified: int}> */
    #[Computed]
    public function items(): array
    {
        return app(StagedDownloads::class)->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function folders(): array
    {
        $options = ['' => 'Mis archivos (inicio)'];
        foreach (app(PersonalFiles::class)->folderPaths(Auth::user()) as $id => $path) {
            $options[(string) $id] = $path;
        }
        asort($options);

        return $options;
    }

    public function save(StagedDownloads $downloads, string $id): void
    {
        $this->authorizeAction();
        $folder = $this->folder === '' ? null : (int) $this->folder;
        abort_unless($folder === null || array_key_exists((string) $folder, $this->folders), 422);
        $file = DomainErrors::guard('download', fn () => $downloads->save(Auth::user(), $id, $folder));
        unset($this->items);
        $this->toast('«'.$file->name.'» guardado en Mis archivos');
    }

    public function discard(StagedDownloads $downloads, string $id): void
    {
        $this->authorizeAction();
        DomainErrors::guard('download', fn () => $downloads->discard($id));
        unset($this->items);
        $this->toast('Descarga descartada');
    }

    private function authorizeAction(): void
    {
        $this->throttle('admin');
        Gate::authorize('manage-downloads');
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Descargas por guardar" description="Lo que MeTube ha terminado de descargar. Guárdalo en tu Mis archivos (cuenta en tu espacio) o descártalo." />

    <x-ui::stack gap="lg">
        @error('download')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::toolbar>
                    <x-ui::link :href="route('services.open', 'downloads')">Abrir Descargas (MeTube)</x-ui::link>
                    <x-slot:end>
                        <x-ui::select name="folder" label="Guardar en" :options="$this->folders" wire:model="folder" :value="$folder" help="Música y Vídeos hacen que también aparezca en Navidrome o Jellyfin." />
                    </x-slot:end>
                </x-ui::toolbar>
                <x-ui::table label="Descargas terminadas">
                    <x-slot:head><tr><th>Archivo</th><th>Tamaño</th><th>Terminado</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                    @forelse ($this->items as $item)
                        <tr wire:key="download-{{ $item['id'] }}">
                            <x-ui::td label="Archivo" primary>{{ $item['folder'] === '' ? $item['name'] : $item['folder'].' / '.$item['name'] }}</x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ Bytes::format($item['bytes']) }}</x-ui::td>
                            <x-ui::td label="Terminado" nowrap>{{ Carbon::createFromTimestamp($item['modified'])->diffForHumans() }}</x-ui::td>
                            <x-ui::td align="end" nowrap>
                                <x-ui::button size="sm" icon="download" wire:click="save('{{ $item['id'] }}')" wire:loading.attr="disabled">Guardar</x-ui::button>
                                <x-ui::confirm title="¿Descartar esta descarga?">
                                    <x-slot:trigger><x-ui::button variant="ghost" size="sm">Descartar</x-ui::button></x-slot:trigger>
                                    Se borra de Descargas. Si la quieres más tarde, tendrás que descargarla otra vez.
                                    <x-slot:action><x-ui::button variant="danger" wire:click="discard('{{ $item['id'] }}')">Descartar</x-ui::button></x-slot:action>
                                </x-ui::confirm>
                            </x-ui::td>
                        </tr>
                    @empty
                        <x-ui::table-empty colspan="4">No hay descargas terminadas. Abre Descargas, pega el enlace y vuelve aquí cuando termine.</x-ui::table-empty>
                    @endforelse
                </x-ui::table>
            </x-ui::stack>
        </x-ui::panel>
    </x-ui::stack>
</div>
