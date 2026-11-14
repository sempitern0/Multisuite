<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\FileExport;
use App\Services\Files\FileExports;
use App\Support\DomainErrors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Descargas ZIP')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    /** @return Collection<int, FileExport> */
    #[Computed]
    public function exports(): Collection
    {
        return app(FileExports::class)->forUser(Auth::user());
    }

    /** While something is being prepared the list refreshes itself. */
    #[Computed]
    public function pending(): bool
    {
        return $this->exports->contains(fn (FileExport $export) => in_array($export->state, ['queued', 'building'], true));
    }

    public function discard(string $exportId, FileExports $exports): void
    {
        $this->throttle('files');
        abort_unless(Str::isUuid($exportId), 404);
        DomainErrors::guard('export', fn () => $exports->discard(Auth::user(), $exportId));
        unset($this->exports, $this->pending);
        $this->toast('Descarga eliminada');
    }
}; ?>

<div @if ($this->pending) wire:poll.5s @endif>
    <x-ui::breadcrumbs :items="[['label' => 'Mis archivos', 'href' => route('files.index')], ['label' => 'Descargas ZIP', 'href' => route('files.exports.index')]]" />
    <x-ui::page-header title="Descargas ZIP" :description="'Cada ZIP se puede descargar durante '.(int) config('nas.export_ttl_hours').' horas y después se borra solo. No ocupa espacio de tu cuota.'" />

    <x-ui::stack gap="md">
        @error('export')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        <x-ui::table label="Descargas ZIP">
            <x-slot:head><tr><th>Nombre</th><th>Contenido</th><th>Estado</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
            @forelse ($this->exports as $export)
                <tr wire:key="export-{{ $export->id }}">
                    <x-ui::td primary>{{ $export->name }}</x-ui::td>
                    <x-ui::td label="Contenido">{{ $export->item_count }} {{ $export->item_count === 1 ? 'archivo' : 'archivos' }}</x-ui::td>
                    <x-ui::td label="Estado">
                        @switch($export->state)
                            @case('queued') <x-ui::status tone="warn">En cola</x-ui::status> @break
                            @case('building') <x-ui::status tone="warn">Preparando</x-ui::status> @break
                            @case('ready') <x-ui::status>{{ \App\Support\Bytes::format((int) $export->bytes) }} · hasta el {{ $export->expires_at?->format('d/m/Y H:i') }}</x-ui::status> @break
                            @default <x-ui::status tone="bad">No se pudo generar: {{ $export->error }}</x-ui::status>
                        @endswitch
                    </x-ui::td>
                    <x-ui::td align="end">
                        @if ($export->state === 'ready')
                            <x-ui::button size="sm" icon="download" :href="route('files.exports.download', ['exportId' => $export->id])">Descargar</x-ui::button>
                        @endif
                        @if (in_array($export->state, ['ready', 'failed'], true))
                            <x-ui::button variant="ghost" size="sm" wire:click="discard('{{ $export->id }}')" :aria-label="'Eliminar '.$export->name">Eliminar</x-ui::button>
                        @endif
                    </x-ui::td>
                </tr>
            @empty
                <x-ui::table-empty colspan="4">No tienes descargas ZIP. Elige archivos o carpetas en Mis archivos y pulsa «Descargar selección en ZIP».</x-ui::table-empty>
            @endforelse
        </x-ui::table>
    </x-ui::stack>
</div>
