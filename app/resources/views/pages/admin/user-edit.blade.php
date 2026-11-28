<?php

use App\Actions\Users\ChangeUsername;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\SetServiceAccess;
use App\Actions\Users\SetSuspension;
use App\Actions\Users\SetVideoRating;
use App\Actions\Users\UpdateUser;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\JellyfinAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Support\Arr;
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

    public string $role = \App\Enums\Role::Member->value;

    public ?string $videoRating = null;

    public string $username = '';

    public bool $email_confirmed = false;

    public string $confirm_email = '';

    public function mount(User $user): void
    {
        Gate::authorize('update', $user);
        $this->userId = $user->id;
        $this->fill($user->only('name', 'email', 'role'));
        $this->videoRating = $user->video_max_rating === null ? '' : (string) $user->video_max_rating;
        $this->username = (string) $user->username;
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

    /**
     * Every service the portal manages, with this person's access and account state.
     *
     * @return list<array{id: string, name: string, enabled: bool, username: ?string, state: ?array{label: string, tone: string}}>
     */
    #[Computed]
    public function services(): array
    {
        $accounts = $this->managed->serviceAccounts()->get()->keyBy('service');
        $serviceAccounts = app(ServiceAccounts::class);

        return array_map(fn (string $id): array => [
            'id' => $id,
            'name' => (string) config('portal.services.'.$id.'.name', $id),
            'enabled' => $this->managed->service_access === null || in_array($id, $this->managed->service_access, true),
            'username' => $accounts->get($id)?->username,
            'state' => $accounts->get($id)?->state(),
        ], $serviceAccounts->available());
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

    public function saveUsername(ChangeUsername $change): void
    {
        $this->throttle('admin');
        $data = $this->validate($change->rules($this->managed));
        $change->handle(Auth::user(), $this->managed, $data['username']);
        unset($this->managed, $this->services);
        $this->toast('Nombre de usuario cambiado');
    }

    public function setService(string $service, bool $enabled, SetServiceAccess $access): void
    {
        $this->throttle('admin');
        $access->handle(Auth::user(), $this->managed, $service, $enabled);
        unset($this->managed, $this->services);
        $this->toast($enabled ? 'Servicio activado' : 'Servicio desactivado');
    }

    public function saveVideoRating(SetVideoRating $set): void
    {
        $this->throttle('admin');
        $set->handle(Auth::user(), $this->managed, $this->videoRating === null || $this->videoRating === '' ? null : (int) $this->videoRating);
        unset($this->managed);
        $this->toast('Límite de Vídeo guardado');
    }

    public function setSuspended(bool $suspend, SetSuspension $suspension): void
    {
        $this->throttle('admin');
        $suspension->handle(Auth::user(), $this->managed, $suspend);
        unset($this->managed, $this->services);
        $this->toast($suspend ? 'Cuenta suspendida' : 'Cuenta reactivada');
    }

    public function removeAvatar(RemoveAvatar $remove): void
    {
        $this->throttle('admin');
        $remove->handle(Auth::user(), $this->managed);
        unset($this->managed);
        $this->toast('Foto retirada');
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
    @php($person = $this->managed)
    <x-ui::page-header eyebrow="Personas" :back="route('users.index')" back-label="Personas" :title="'Gestionar a '.$person->name" description="Cambia sus datos, su acceso a cada servicio o el estado de su cuenta. Su contraseña solo puede cambiarla la propia persona.">
    </x-ui::page-header>

    <x-ui::container>
        <x-ui::stack gap="lg">
            <x-ui::panel aria-labelledby="identity">
                <x-ui::row>
                    <x-ui::person size="lg" :name="$person->name" :sub="($person->username ? '@'.$person->username.' · ' : '').$person->roleLabel()" :src="$person->avatarUrl()" :initials="$person->initials()" id="identity" />
                    <x-ui::actions>
                        @if ($person->isSuspended())
                            <x-ui::status tone="bad">Suspendida desde el {{ $person->suspended_at?->format('d/m/Y') }}</x-ui::status>
                        @else
                            <x-ui::status>Activa</x-ui::status>
                        @endif
                        @if ($person->avatar_hash)
                            <x-ui::confirm title="¿Retirar su foto?">
                                <x-slot:trigger><x-ui::button variant="ghost" size="sm" icon="trash">Retirar foto</x-ui::button></x-slot:trigger>
                                Se mostrarán sus iniciales. Podrá subir otra foto cuando quiera.
                                <x-slot:action><x-ui::button variant="danger" wire:click="removeAvatar">Retirar foto</x-ui::button></x-slot:action>
                            </x-ui::confirm>
                        @endif
                    </x-ui::actions>
                </x-ui::row>
            </x-ui::panel>

            <x-ui::panel aria-labelledby="account-data">
                <x-ui::stack gap="md">
                    <x-ui::heading id="account-data">Datos de la cuenta</x-ui::heading>
                    <x-ui::form wire:submit="save">
                        <x-ui::field name="name" label="Nombre" wire:model="name" required maxlength="255" />
                        <x-ui::field name="email" type="email" label="Correo" wire:model="email" required maxlength="255" />
                        <x-ui::checkbox name="email_confirmed" wire:model="email_confirmed" label="Si cambio el correo, he comprobado que pertenece a esta persona" />
                        @if (count($this->roles) > 1)
                            <x-ui::choice-group name="role" label="Rol" wire:model="role" :options="$this->roles" />
                        @endif
                        <x-ui::button type="submit">Guardar cambios</x-ui::button>
                    </x-ui::form>
                </x-ui::stack>
            </x-ui::panel>

            <x-ui::panel aria-labelledby="username-heading">
                <x-ui::stack gap="md">
                    <x-ui::heading id="username-heading">Nombre de usuario</x-ui::heading>
                    <x-ui::text tone="muted">Con él entra en Multisuite y en sus servicios. Al cambiarlo se cambia también en cada servicio, sin tocar su contraseña ni sus datos; avísale del nuevo nombre.</x-ui::text>
                    <x-ui::form wire:submit="saveUsername" inline>
                        <x-ui::field name="username" label="Nombre de usuario" wire:model="username" required minlength="3" maxlength="32" autocomplete="off"
                            help="De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo." />
                        <x-ui::button type="submit" variant="secondary">Cambiar usuario</x-ui::button>
                    </x-ui::form>
                </x-ui::stack>
            </x-ui::panel>

            @if ($this->services !== [])
                <x-ui::panel aria-labelledby="linked-services">
                    <x-ui::stack gap="md">
                        <x-ui::heading id="linked-services">Servicios</x-ui::heading>
                        <x-ui::text tone="muted">Desactivar un servicio bloquea su acceso en el propio servicio y conserva sus datos. Al activarlo de nuevo, quedará listo la próxima vez que entre en Multisuite con su contraseña.</x-ui::text>
                        @error('services')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
                        @foreach ($this->services as $service)
                            <x-ui::row wire:key="service-{{ $service['id'] }}">
                                <span>
                                    <strong>{{ $service['name'] }}</strong>
                                    @if ($service['username'])<x-ui::text as="span" tone="muted"> · {{ $service['username'] }}</x-ui::text>@endif
                                </span>
                                <x-ui::actions>
                                    @if (! $service['enabled'])
                                        <x-ui::status tone="muted">Sin acceso</x-ui::status>
                                        <x-ui::button variant="secondary" size="sm" wire:click="setService('{{ $service['id'] }}', true)">Activar</x-ui::button>
                                    @else
                                        <x-ui::status :tone="$service['state']['tone'] ?? 'warn'">{{ $service['state']['label'] ?? 'Se activará cuando elija su contraseña' }}</x-ui::status>
                                        <x-ui::confirm :title="'¿Desactivar '.$service['name'].'?'">
                                            <x-slot:trigger><x-ui::button variant="ghost" size="sm">Desactivar</x-ui::button></x-slot:trigger>
                                            No podrá entrar en {{ $service['name'] }}, tampoco desde sus aplicaciones. Sus datos se conservan. Las sesiones que ya tenga abiertas en el servicio pueden seguir hasta que caduquen.
                                            <x-slot:action><x-ui::button variant="danger" wire:click="setService('{{ $service['id'] }}', false)">Desactivar</x-ui::button></x-slot:action>
                                        </x-ui::confirm>
                                    @endif
                                </x-ui::actions>
                            </x-ui::row>
                        @endforeach
                        @if (collect($this->services)->contains(fn (array $service): bool => $service['id'] === 'video' && $service['enabled']))
                            <x-ui::form wire:submit="saveVideoRating" inline>
                                <x-ui::select name="videoRating" label="Vídeo: contenido permitido" wire:model="videoRating"
                                    :options="['' => 'Sin límite'] + JellyfinAccounts::AGE_LIMITS"
                                    help="Lo aplica Jellyfin en la web y en sus aplicaciones. Con límite, las películas y series sin clasificación quedan ocultas; sus propios vídeos siguen visibles." />
                                <x-ui::button type="submit" variant="secondary">Guardar límite</x-ui::button>
                            </x-ui::form>
                        @endif
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            @if ($this->storage)
                <x-ui::panel aria-labelledby="storage-space">
                    <x-ui::stack gap="sm">
                        <x-ui::heading id="storage-space">Espacio de almacenamiento</x-ui::heading>
                        <x-ui::meter :value="\App\Support\Bytes::percent($this->storage['used'], $this->storage['limit'])" :label="'Espacio usado por '.$person->name">
                            {{ \App\Support\Bytes::format($this->storage['used']) }} usados de {{ \App\Support\Bytes::format($this->storage['limit']) }}{{ $this->storage['followsDefault'] ? ' (espacio por defecto)' : '' }}.
                        </x-ui::meter>
                        <x-ui::link :href="route('storage.index', ['q' => $person->email])">Cambiar su espacio</x-ui::link>
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            <x-ui::panel aria-labelledby="suspension">
                <x-ui::stack gap="sm">
                    <x-ui::heading id="suspension">{{ $person->isSuspended() ? 'Reactivar la cuenta' : 'Suspender la cuenta' }}</x-ui::heading>
                    @error('suspension')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
                    @if ($person->isSuspended())
                        <x-ui::text>Podrá volver a entrar en Multisuite. Sus servicios quedarán listos la próxima vez que entre con su contraseña.</x-ui::text>
                        <x-ui::actions><x-ui::button variant="secondary" wire:click="setSuspended(false)">Reactivar cuenta</x-ui::button></x-ui::actions>
                    @else
                        <x-ui::text>El acceso al portal se bloquea de inmediato y sus invitaciones pendientes caducan. Multisuite seguirá intentando bloquear cada servicio hasta que responda. Sus archivos se conservan.</x-ui::text>
                        <x-ui::actions>
                            <x-ui::confirm title="¿Suspender esta cuenta?">
                                <x-slot:trigger><x-ui::button variant="secondary">Suspender cuenta</x-ui::button></x-slot:trigger>
                                {{ $person->name }} no podrá entrar hasta que reactives su cuenta. No se borra nada.
                                <x-slot:action><x-ui::button variant="danger" wire:click="setSuspended(true)">Suspender</x-ui::button></x-slot:action>
                            </x-ui::confirm>
                        </x-ui::actions>
                    @endif
                </x-ui::stack>
            </x-ui::panel>

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
