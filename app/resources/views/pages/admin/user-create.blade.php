<?php

use App\Actions\Users\CreateUser;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Crear cuenta')] class extends Component {
    use NotifiesInterface, ThrottlesActions;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $role = 'member';

    /** @var list<string> */
    public array $services = [];

    public string $password = '';

    public string $password_confirmation = '';

    public function boot(): void
    {
        Gate::authorize('manage-invitations');
    }

    public function mount(): void
    {
        $this->services = $this->availableServices;
    }

    /** @return array<string, string> */
    #[Computed]
    public function roles(): array
    {
        return Auth::user()->role === 'owner' ? ['member' => 'Miembro', 'admin' => 'Administrador'] : ['member' => 'Miembro'];
    }

    /** @return list<string> */
    #[Computed]
    public function availableServices(): array
    {
        return app(ServiceAccounts::class)->available();
    }

    public function create(CreateUser $create): void
    {
        $this->throttle('admin');
        try {
            /** @var array{name: string, username?: ?string, email: string, role: string, password: string, services?: list<string>} $data */
            $data = $this->validate($create->rules());
        } catch (ValidationException $exception) {
            // Never send a rejected password back to the browser.
            $this->reset('password', 'password_confirmation');
            throw $exception;
        }
        $user = $create->handle(Auth::user(), $data);
        $this->reset('name', 'username', 'email', 'password', 'password_confirmation');
        $this->role = 'member';
        $this->services = $this->availableServices;
        $this->toast('Cuenta de '.$user->name.' creada. Entrégale la contraseña temporal por un canal seguro');
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Personas" title="Crear cuenta" description="Para quien no puede recibir una invitación. No se envía ningún correo: tú le entregas una contraseña temporal y deberá cambiarla la primera vez que entre.">
        <x-slot:actions>
            <x-ui::button variant="ghost" :href="route('users.index')" wire:navigate>Volver a Personas</x-ui::button>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::container>
        <x-ui::panel>
            <x-ui::form wire:submit="create">
                <x-ui::field name="name" label="Nombre" wire:model="name" required autocomplete="off" />
                <x-ui::field name="username" label="Nombre de usuario (opcional)" wire:model="username" autocomplete="off" minlength="3" maxlength="32"
                    help="De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo. Será también su acceso a Música." />
                <x-ui::field name="email" type="email" label="Correo de acceso" wire:model="email" required autocomplete="off"
                    help="Se da por verificado: comprueba antes que pertenece a esta persona." />
                @if (count($this->roles) > 1)
                    <x-ui::choice-group name="role" label="Rol" wire:model="role" :options="$this->roles"
                        help="El rol de propietario solo se asigna desde la consola del servidor." />
                @endif
                @foreach ($this->availableServices as $service)
                    <x-ui::checkbox name="services[]" :value="$service" wire:model="services" :label="'Preparar su cuenta en '.config('portal.services.'.$service.'.name')" />
                @endforeach
                <x-ui::password name="password" label="Contraseña temporal" wire:model="password" required autocomplete="new-password" />
                <x-ui::password name="password_confirmation" label="Repite la contraseña temporal" wire:model="password_confirmation" required autocomplete="new-password"
                    help="El acceso a los servicios elegidos se prepara cuando la persona cambia esta contraseña." />
                <x-ui::button type="submit" icon="plus">Crear cuenta</x-ui::button>
            </x-ui::form>
        </x-ui::panel>
    </x-ui::container>
</div>
