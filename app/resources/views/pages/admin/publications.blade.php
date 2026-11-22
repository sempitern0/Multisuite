<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\Publication;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\Publications;
use App\Services\Storage\StorageReservations;
use App\Support\Bytes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Publication for everyone (S04b, docs/STORAGE.md): administrators publish, reject, withdraw or
 * delete; App\Services\Files\Publications applies the rules and authorises every call.
 */
new #[Title('Para todos')] class extends Component
{
    use NotifiesInterface, ThrottlesActions;

    public function mount(): void
    {
        Gate::authorize('publish-content');
    }

    /** @return Collection<int, Publication> */
    #[Computed]
    public function pending(): Collection
    {
        return app(Publications::class)->pending();
    }

    /** @return Collection<int, Publication> */
    #[Computed]
    public function published(): Collection
    {
        return app(Publications::class)->published();
    }

    public function service(Publication $publication): string
    {
        return ConnectedFolders::CONNECTIONS[$publication->connection]['name'] ?? $publication->connection;
    }

    public function publish(Publications $publications, int $id): void
    {
        $this->run(fn () => $publications->publish(Auth::user(), $id), 'Publicado para todos');
    }

    public function reject(Publications $publications, int $id): void
    {
        $this->run(fn () => $publications->reject(Auth::user(), $id), 'Propuesta rechazada');
    }

    public function withdraw(Publications $publications, int $id): void
    {
        $this->run(fn () => $publications->withdraw(Auth::user(), $id), 'Retirado y devuelto a quien lo aportó');
    }

    public function destroy(Publications $publications, int $id): void
    {
        $this->run(fn () => $publications->destroy(Auth::user(), $id), 'Eliminado del NAS');
    }

    private function run(callable $action, string $message): void
    {
        $this->throttle('admin');
        Gate::authorize('publish-content');
        $action();
        unset($this->pending, $this->published);
        $this->toast($message);
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Para todos" description="Lo que cada persona propone desde sus carpetas conectadas. Al publicarlo, lo ven todos los que tienen el servicio y su espacio pasa al fondo común." />

    <x-ui::stack gap="lg">
        @error('publication')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
        <x-ui::metrics :items="[
            ['label' => 'Pendientes de decidir', 'value' => (string) $this->pending->count(), 'tone' => $this->pending->isEmpty() ? null : 'accent'],
            ['label' => 'Publicado', 'value' => (string) $this->published->count()],
            ['label' => 'Fondo común', 'value' => Bytes::format(app(StorageReservations::class)->commonBytes()), 'sub' => 'No cuenta en el espacio de nadie'],
        ]" />

        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::heading>Propuestas</x-ui::heading>
                <x-ui::table label="Propuestas pendientes">
                    <x-slot:head><tr><th>Contenido</th><th>Servicio</th><th>Propuesto por</th><th>Tamaño</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                    @forelse ($this->pending as $publication)
                        <tr wire:key="pending-{{ $publication->id }}">
                            <x-ui::td label="Contenido" primary>{{ $publication->title }}</x-ui::td>
                            <x-ui::td label="Servicio">{{ $this->service($publication) }}</x-ui::td>
                            <x-ui::td label="Propuesto por">{{ $publication->contributor?->name ?? 'Cuenta eliminada' }}</x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ Bytes::format($publication->bytes) }}</x-ui::td>
                            <x-ui::td align="end" nowrap>
                                <x-ui::button size="sm" wire:click="publish({{ $publication->id }})">Publicar</x-ui::button>
                                <x-ui::confirm title="¿Rechazar esta propuesta?">
                                    <x-slot:trigger><x-ui::button variant="ghost" size="sm">Rechazar</x-ui::button></x-slot:trigger>
                                    El archivo sigue en Mis archivos de quien lo propuso, solo para esa persona.
                                    <x-slot:action><x-ui::button variant="danger" wire:click="reject({{ $publication->id }})">Rechazar propuesta</x-ui::button></x-slot:action>
                                </x-ui::confirm>
                            </x-ui::td>
                        </tr>
                    @empty
                        <x-ui::table-empty colspan="5">No hay propuestas pendientes.</x-ui::table-empty>
                    @endforelse
                </x-ui::table>
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::heading>Publicado para todos</x-ui::heading>
                <x-ui::table label="Contenido publicado">
                    <x-slot:head><tr><th>Contenido</th><th>Servicio</th><th>Aportado por</th><th>Tamaño</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                    @forelse ($this->published as $publication)
                        <tr wire:key="published-{{ $publication->id }}">
                            <x-ui::td label="Contenido" primary>
                                {{ $publication->title }}
                                @if ($publication->withdrawal_requested_at !== null) <x-ui::tag tone="blue">Pide retirarlo</x-ui::tag>@endif
                            </x-ui::td>
                            <x-ui::td label="Servicio">{{ $this->service($publication) }}</x-ui::td>
                            <x-ui::td label="Aportado por">{{ $publication->contributor?->name ?? 'Propiedad común' }}</x-ui::td>
                            <x-ui::td label="Tamaño" nowrap>{{ Bytes::format($publication->bytes) }}</x-ui::td>
                            <x-ui::td align="end" nowrap>
                                @if ($publication->contributor_id !== null && $publication->user_file_id !== null)
                                    <x-ui::confirm title="¿Retirarlo y devolverlo?">
                                        <x-slot:trigger><x-ui::button variant="ghost" size="sm">Retirar</x-ui::button></x-slot:trigger>
                                        Dejará de verse para todos y volverá a contar en el espacio de {{ $publication->contributor?->name }}. Si no le cabe, no se retira.
                                        <x-slot:action><x-ui::button wire:click="withdraw({{ $publication->id }})">Retirar y devolver</x-ui::button></x-slot:action>
                                    </x-ui::confirm>
                                @endif
                                <x-ui::confirm title="¿Eliminar del NAS?">
                                    <x-slot:trigger><x-ui::button variant="ghost" size="sm">Eliminar</x-ui::button></x-slot:trigger>
                                    Se borra para siempre, también de Mis archivos de quien lo aportó. No se puede deshacer.
                                    <x-slot:action><x-ui::button variant="danger" wire:click="destroy({{ $publication->id }})">Eliminar del NAS</x-ui::button></x-slot:action>
                                </x-ui::confirm>
                            </x-ui::td>
                        </tr>
                    @empty
                        <x-ui::table-empty colspan="5">Todavía no se ha publicado nada para todos.</x-ui::table-empty>
                    @endforelse
                </x-ui::table>
            </x-ui::stack>
        </x-ui::panel>
    </x-ui::stack>
</div>
