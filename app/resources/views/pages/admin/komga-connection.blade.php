<?php

use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\KomgaAccounts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Conexión de Lectura')] class extends Component {
    use ForgetsSecrets, NotifiesInterface, ThrottlesActions;

    public string $email = '';

    public string $password = '';

    public function boot(): void
    {
        Gate::authorize('manage-storage-pools');
        abort_unless(config('portal.services.komga.enabled'), 404);
    }

    public function connect(): void
    {
        $this->throttle('admin');
        $this->forgetting(['password'], function (): void {
            $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024']]);
            try {
                app(KomgaAccounts::class)->connect(Auth::user(), $this->email, $this->password);
            } catch (RuntimeException) {
                $this->addError('password', 'No hemos podido conectar. Comprueba el correo y la contraseña del administrador de Komga y que Lectura esté disponible.');

                return;
            }
            $this->toast('Lectura conectada. Las cuentas se prepararán automáticamente al entrar en Multisuite.');
            $this->redirectRoute('komga.confirm');
        });
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Conectar Lectura" description="Autoriza Multisuite una vez. Después crearemos las cuentas y sus bibliotecas privadas automáticamente." />
    <x-ui::container>
        <x-ui::panel>
            <x-ui::form wire:submit="connect">
                <x-ui::field name="email" type="email" label="Correo del administrador de Komga" wire:model="email" required autocomplete="username"
                    help="Si Komga está recién instalado, estos datos crearán su primera cuenta. Si ya lo usabas, introduce su cuenta administradora." />
                <x-ui::password name="password" label="Contraseña de Komga" wire:model="password" required autocomplete="current-password" />
                <x-ui::button type="submit">Conectar Lectura</x-ui::button>
            </x-ui::form>
        </x-ui::panel>
    </x-ui::container>
</div>
