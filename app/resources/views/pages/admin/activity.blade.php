<?php

use App\Models\AdminAuditEvent;
use App\Services\AdminAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Actividad')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $service = '';

    /** Runs on every request, not only the first render. */
    public function boot(): void
    {
        Gate::authorize('view-admin-audit');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('action', 'service');
        $this->resetPage();
    }

    /** @return array<string, string> */
    #[Computed]
    public function labels(): array
    {
        return AdminAudit::actionLabels();
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function services(): Collection
    {
        return AdminAuditEvent::whereNotNull('service_id')->distinct()->orderBy('service_id')->pluck('service_id');
    }

    #[Computed]
    public function events(): LengthAwarePaginator
    {
        // Unknown values from the URL are ignored instead of failing the page.
        $action = array_key_exists($this->action, $this->labels) ? $this->action : '';
        $service = preg_match('/^[a-z0-9-]{1,64}$/D', $this->service) ? $this->service : '';

        return AdminAuditEvent::query()
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($service !== '', fn ($query) => $query->where('service_id', $service))
            ->orderByDesc('id')
            ->paginate(Auth::user()->preferences()->perPage());
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Actividad" :description="'Cambios administrativos y eventos de los servicios. Horas en '.config('app.timezone').'.'" />

    <x-ui::stack gap="md">
        <x-ui::toolbar>
            <x-ui::select name="action" label="Acción" placeholder="Todas" wire:model.live="action" :options="$this->labels" />
            <x-ui::select name="service" label="Servicio" placeholder="Todos" wire:model.live="service"
                :options="$this->services->mapWithKeys(fn ($id) => [$id => config('portal.services.'.$id.'.name', $id)])->all()" />
            @if ($action !== '' || $service !== '')
                <x-slot:end><x-ui::link wire:click="clearFilters">Quitar filtros</x-ui::link></x-slot:end>
            @endif
        </x-ui::toolbar>

        <x-ui::table label="Historial de actividad" :paginator="$this->events">
            <x-slot:head>
                <tr><th>Acción</th><th>Quién</th><th>Sobre</th><th>Detalles</th><th>Fecha</th></tr>
            </x-slot:head>
            @forelse ($this->events as $event)
                <tr wire:key="event-{{ $event->id }}">
                    <x-ui::td primary :sub="$event->service_id !== null ? config('portal.services.'.$event->service_id.'.name', $event->service_id) : null">{{ $this->labels[$event->action] ?? 'Acción administrativa' }}</x-ui::td>
                    <x-ui::td label="Quién">{{ $event->actor_name }}</x-ui::td>
                    <x-ui::td label="Sobre">{{ $event->subject_name }}</x-ui::td>
                    <x-ui::td label="Detalles">
                        @if (! empty($event->details['fields']))
                            Campos: {{ implode(', ', array_map(fn ($field) => ['name' => 'nombre', 'email' => 'correo', 'role' => 'rol'][$field] ?? $field, $event->details['fields'])) }}.
                        @endif
                        @if (($event->details['previous_role'] ?? null) !== null && $event->details['previous_role'] !== $event->details['role'])
                            Rol: {{ $event->details['previous_role'] }} → {{ $event->details['role'] }}.
                        @endif
                        @if (array_key_exists('limit_bytes', $event->details))
                            @if (($event->details['pool'] ?? null) !== null)Disco {{ $event->details['pool'] }}. @endif
                            Límite: {{ ($event->details['previous_limit_bytes'] ?? null) === null ? 'sin configurar' : \App\Support\Bytes::format((int) $event->details['previous_limit_bytes']) }} → {{ \App\Support\Bytes::format((int) $event->details['limit_bytes']) }}.
                        @endif
                    </x-ui::td>
                    <x-ui::td label="Fecha"><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('d/m/Y H:i') }}</time></x-ui::td>
                </tr>
            @empty
                <x-ui::table-empty colspan="5">Todavía no hay actividad para este filtro.</x-ui::table-empty>
            @endforelse
        </x-ui::table>
    </x-ui::stack>
</div>
