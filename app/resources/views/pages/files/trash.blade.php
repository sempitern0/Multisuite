<?php

use App\Livewire\Concerns\ManagesOwnFiles;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\Files\PersonalFiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Papelera')] class extends Component {
    use ManagesOwnFiles, NotifiesInterface, ThrottlesActions;

    /** @return Collection<int, \App\Models\UserFile> */
    #[Computed]
    public function entries(): Collection
    {
        return app(PersonalFiles::class)->trashed(Auth::user());
    }

    #[Computed]
    public function bytes(): int
    {
        return app(PersonalFiles::class)->trashBytes(Auth::user());
    }

    public function restore(int $fileId): void
    {
        $this->changed('Archivo restaurado como «'.$this->restoreOwnFile($fileId)->name.'»');
    }

    public function purge(int $fileId): void
    {
        $this->purgeOwnFile($fileId);
        $this->changed('Borrado definitivamente. El espacio ya está disponible');
    }

    public function emptyTrash(PersonalFiles $files): void
    {
        $this->throttle('files');
        $failed = $files->emptyTrash(Auth::user());
        $this->changed($failed === 0
            ? 'Papelera vaciada. El espacio ya está disponible'
            : $failed.' archivos no se pudieron borrar (el disco no está disponible) y siguen en la papelera', $failed === 0 ? 'ok' : 'warn');
    }

    private function changed(string $message, string $tone = 'ok'): void
    {
        unset($this->entries, $this->bytes);
        $this->toast($message, $tone);
    }
}; ?>

<div>
    <x-ui::breadcrumbs :items="[['label' => 'Mis archivos', 'href' => route('files.index')], ['label' => 'Papelera', 'href' => route('files.trash')]]" />
    <x-ui::page-header title="Papelera" :description="'Los archivos se borran definitivamente a los '.(int) config('nas.trash_days').' días. Mientras están aquí siguen ocupando '.\App\Support\Bytes::format($this->bytes).' de tu espacio.'">
        @if ($this->entries->isNotEmpty())
            <x-slot:actions>
                <x-ui::confirm title="¿Vaciar la papelera?">
                    <x-slot:trigger><x-ui::button variant="danger" icon="trash">Vaciar papelera</x-ui::button></x-slot:trigger>
                    Los archivos y sus versiones se borrarán definitivamente. No se puede deshacer.
                    <x-slot:action><x-ui::button variant="danger" wire:click="emptyTrash">Vaciar papelera</x-ui::button></x-slot:action>
                </x-ui::confirm>
            </x-slot:actions>
        @endif
    </x-ui::page-header>

    <x-ui::stack gap="md">
        @foreach (['file_trash', 'file_purge'] as $field)
            @error($field)<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        @endforeach
        <x-ui::table label="Archivos en la papelera">
            <x-slot:head><tr><th>Nombre</th><th>Tamaño</th><th>En la papelera desde</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
            @forelse ($this->entries as $entry)
                <tr wire:key="trashed-{{ $entry->id }}">
                    <x-ui::td><x-ui::entry :href="route('files.show', $entry)">{{ $entry->name }}</x-ui::entry></x-ui::td>
                    <x-ui::td label="Tamaño">{{ \App\Support\Bytes::format((int) $entry->size) }}</x-ui::td>
                    <x-ui::td label="Desde">{{ $entry->trashed_at?->format('d/m/Y') }}</x-ui::td>
                    <x-ui::td align="end">
                        <x-ui::button variant="secondary" size="sm" wire:click="restore({{ $entry->id }})" :aria-label="'Restaurar '.$entry->name">Restaurar</x-ui::button>
                        <x-ui::confirm title="¿Borrar definitivamente?">
                            <x-slot:trigger><x-ui::button variant="ghost" size="sm" :aria-label="'Borrar definitivamente '.$entry->name">Borrar</x-ui::button></x-slot:trigger>
                            Se borrará «{{ $entry->name }}» con todas sus versiones. No se puede deshacer.
                            <x-slot:action><x-ui::button variant="danger" wire:click="purge({{ $entry->id }})">Borrar definitivamente</x-ui::button></x-slot:action>
                        </x-ui::confirm>
                    </x-ui::td>
                </tr>
            @empty
                <x-ui::table-empty colspan="4">La papelera está vacía.</x-ui::table-empty>
            @endforelse
        </x-ui::table>
    </x-ui::stack>
</div>
