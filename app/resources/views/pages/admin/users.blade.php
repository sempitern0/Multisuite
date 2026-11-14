<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Personas')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->search = Str::limit(trim($this->search), 100, '');
        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $query = Str::limit(trim($this->search), 100, '');

        return User::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $pattern = '%'.Str::lower($query).'%';
                $builder->where(function ($search) use ($pattern): void {
                    $search->whereRaw('LOWER(name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(username) LIKE ?', [$pattern]);
                });
            })
            ->orderBy('name')->orderBy('id')
            ->paginate(Auth::user()->preferences()->perPage());
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Personas" description="Quién tiene cuenta en Multisuite y en qué estado está.">
        <x-slot:actions>
            <x-ui::button :href="route('users.create')" icon="plus" wire:navigate>Crear usuario</x-ui::button>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::stack gap="md">
        <x-ui::toolbar>
            <x-ui::field name="q" type="search" label="Buscar por nombre, usuario o correo" maxlength="100" wire:model.live.debounce.300ms="search" />
        </x-ui::toolbar>

        <x-ui::table label="Personas con cuenta" :paginator="$this->users">
            <x-slot:head>
                <tr><th>Persona</th><th>Usuario</th><th>Rol</th><th>Estado</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
            </x-slot:head>
            @forelse ($this->users as $listedUser)
                <tr wire:key="user-{{ $listedUser->id }}">
                    <x-ui::td primary :sub="$listedUser->email">{{ $listedUser->name }}</x-ui::td>
                    <x-ui::td label="Usuario">{{ $listedUser->username ? '@'.$listedUser->username : '—' }}</x-ui::td>
                    <x-ui::td label="Rol"><x-ui::tag :tone="$listedUser->role === 'member' ? 'grey' : null">{{ $listedUser->roleLabel() }}</x-ui::tag></x-ui::td>
                    <x-ui::td label="Estado">
                        @if ($listedUser->must_change_password)
                            <x-ui::status tone="warn">Debe cambiar su contraseña</x-ui::status>
                        @elseif ($listedUser->hasVerifiedEmail())
                            <x-ui::status>Activa</x-ui::status>
                        @else
                            <x-ui::status tone="muted">Correo sin verificar</x-ui::status>
                        @endif
                    </x-ui::td>
                    <x-ui::td align="end">
                        @can('update', $listedUser)
                            <x-ui::button variant="secondary" size="sm" :href="route('users.edit', $listedUser)" :aria-label="'Gestionar a '.$listedUser->name">Gestionar</x-ui::button>
                        @endcan
                    </x-ui::td>
                </tr>
            @empty
                <x-ui::table-empty colspan="5">No hay personas que coincidan con la búsqueda.</x-ui::table-empty>
            @endforelse
        </x-ui::table>
    </x-ui::stack>
</div>
