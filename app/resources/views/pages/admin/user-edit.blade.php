<?php

use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\User;
use App\Models\UserStorageQuota;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Gestionar persona')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    #[Locked]
    public int $userId;

    public string $name = '';

    public string $email = '';

    public string $role = 'member';

    public bool $email_confirmed = false;

    public string $confirm_email = '';

    public function mount(User $user): void
    {
        Gate::authorize('update', $user);
        $this->userId = $user->id;
        $this->fill($user->only('name', 'email', 'role'));
    }

    /** Re-read and re-authorised on every request; the Actions check again under lock. */
    #[Computed]
    public function managed(): User
    {
        $user = User::findOrFail($this->userId);
        Gate::authorize('update', $user);

        return $user;
    }

    /** @return array<string, string> */
    #[Computed]
    public function roles(): array
    {
        return Auth::user()->assignableRoles();
    }

    /** @return Collection<int, \App\Models\ServiceAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->managed->serviceAccounts()->get();
    }

    /** @return array{used: int, limit: int, followsDefault: bool}|null */
    #[Computed]
    public function storage(): ?array
    {
        if (Gate::denies('view-storage')) {
            return null;
        }
        return UserStorageQuota::summaryFor($this->userId);
    }

    public function save(UpdateUser $update): void
    {
        $this->throttle('admin');
        /** @var array{name: string, email: string, role: string, email_confirmed?: mixed} $data */
        $data = $this->validate(Arr::except($update->rules($this->managed), 'email_confirmed'));
        if ($this->email_confirmed) {
            $data['email_confirmed'] = true;
        }
        $update->handle(Auth::user(), $this->managed, $data);
        $this->email_confirmed = false;
        unset($this->managed);
        $this->toast('Cambios guardados');
    }

    public function delete(DeleteUser $delete): void
    {
        $this->throttle('admin');
        $this->validate(['confirm_email' => ['required', 'string']], ['confirm_email.required' => 'Escribe el correo de esta cuenta para confirmar.']);
        $delete->handle(Auth::user(), $this->managed, $this->confirm_email);
        session()->flash('toast', 'Persona eliminada');
        $this->redirectRoute('users.index', navigate: true);
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Personas" :back="route('users.index')" back-label="Personas" :title="'Gestionar a '.$this->managed->name" description="Cambia sus datos o su rol. Su contraseña solo puede cambiarla la propia persona.">
    </x-ui::page-header>

    <x-ui::container>
        <x-ui::stack gap="lg">
            <x-ui::panel>
                <x-ui::form wire:submit="save">
                    <x-ui::field name="name" label="Nombre" wire:model="name" required maxlength="255" />
                    <x-ui::field name="email" type="email" label="Correo" wire:model="email" required maxlength="255" />
                    <x-ui::checkbox name="email_confirmed" wire:model="email_confirmed" label="Si cambio el correo, he comprobado que pertenece a esta persona" />
                    @if (count($this->roles) > 1)
                        <x-ui::choice-group name="role" label="Rol" wire:model="role" :options="$this->roles" />
                    @endif
                    <x-ui::text tone="muted">Nombre de usuario: {{ $this->managed->username ?? 'no elegido' }}. El nombre de acceso a Música se conserva.</x-ui::text>
                    <x-ui::button type="submit">Guardar cambios</x-ui::button>
                </x-ui::form>
            </x-ui::panel>

            @if ($this->accounts->isNotEmpty())
                <x-ui::panel aria-labelledby="linked-services">
                    <x-ui::stack gap="sm">
                        <x-ui::heading id="linked-services">Servicios vinculados</x-ui::heading>
                        @foreach ($this->accounts as $account)
                            <x-ui::row wire:key="account-{{ $account->id }}">
                                <span>{{ config('portal.services.'.$account->service.'.name', $account->service) }} · {{ $account->username }}</span>
                                <x-ui::status :tone="$account->status === 'ready' ? 'ok' : 'warn'">{{ $account->status === 'ready' ? 'Preparada' : 'Pendiente de preparación' }}</x-ui::status>
                            </x-ui::row>
                        @endforeach
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            @if ($this->storage)
                <x-ui::panel aria-labelledby="storage-space">
                    <x-ui::stack gap="sm">
                        <x-ui::heading id="storage-space">Espacio de almacenamiento</x-ui::heading>
                        <x-ui::meter :value="\App\Support\Bytes::percent($this->storage['used'], $this->storage['limit'])" :label="'Espacio usado por '.$this->managed->name">
                            {{ \App\Support\Bytes::format($this->storage['used']) }} usados de {{ \App\Support\Bytes::format($this->storage['limit']) }}{{ $this->storage['followsDefault'] ? ' (espacio por defecto)' : '' }}.
                        </x-ui::meter>
                        <x-ui::link :href="route('storage.index', ['q' => $this->managed->email])">Cambiar su espacio</x-ui::link>
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            <x-ui::panel tone="danger" aria-labelledby="delete-account">
                <x-ui::stack gap="sm">
                    <x-ui::heading id="delete-account">Eliminar a esta persona</x-ui::heading>
                    <x-ui::text>No se puede deshacer. Se elimina su cuenta de Multisuite y su cuenta de Música, con sus listas y datos personales. Los archivos de la biblioteca musical se conservan.</x-ui::text>
                    @error('deletion')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
                    <x-ui::form wire:submit="delete">
                        <x-ui::field name="confirm_email" label="Escribe el correo de esta cuenta para confirmar" wire:model="confirm_email" autocomplete="off" required />
                        <x-ui::button type="submit" variant="danger" icon="trash">Eliminar definitivamente</x-ui::button>
                    </x-ui::form>
                </x-ui::stack>
            </x-ui::panel>
        </x-ui::stack>
    </x-ui::container>
</div>
