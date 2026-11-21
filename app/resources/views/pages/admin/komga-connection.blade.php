<?php

use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\KomgaAccounts;
use App\Services\KomgaSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Conexión de Lectura')] class extends Component {
    use ForgetsSecrets, NotifiesInterface, ThrottlesActions;

    public bool $existingAccount = false;

    public string $email = '';

    public string $password = '';

    public function boot(): void
    {
        Gate::authorize('manage-storage-pools');
        abort_unless(config('portal.services.komga.enabled'), 404);
    }

    #[Computed]
    public function setupState(): string
    {
        return app(KomgaAccounts::class)->setupState();
    }

    public function connect(): void
    {
        $this->throttle('admin');
        $this->forgetting(['password'], function (): void {
            $this->validate([
                'email' => $this->existingAccount ? ['required', 'email'] : ['nullable', 'string'],
                'password' => $this->existingAccount ? ['required', 'string', 'max:1024'] : ['required', 'string', 'current_password'],
            ]);
            $user = Auth::user();
            try {
                // The default path never trusts a client-supplied email or an arbitrary new password.
                app(KomgaAccounts::class)->connect($user, $this->existingAccount ? $this->email : $user->email, $this->password);
            } catch (LockTimeoutException) {
                $this->addError('password', 'La conexión ya se está preparando. Espera unos segundos y vuelve a intentarlo.');

                return;
            } catch (RuntimeException $exception) {
                $this->addError('password', match ($exception->getMessage()) {
                    'credentials' => 'Komga ya tiene una cuenta y no acepta estos datos. Prueba el correo y la contraseña que usabas antes; si se creó automáticamente, son los del propietario de Multisuite en aquel momento.',
                    'not_admin' => 'La cuenta existe en Komga, pero no es administradora. Necesitamos su cuenta administradora para preparar los demás usuarios.',
                    'api_key_denied' => 'Komga acepta la cuenta, pero no permite crear la clave de conexión. Comprueba sus permisos administrativos.',
                    'rate_limited' => 'Komga ha limitado los intentos. Espera unos minutos antes de volver a probar.',
                    'claim_rejected' => 'Komga no ha aceptado crear la primera cuenta. Actualiza el estado; puede haberse inicializado mientras conectabas.',
                    'conflict' => 'El propietario ya está vinculado a otra cuenta de Komga. Usa esa cuenta para conservar sus bibliotecas y permisos.',
                    default => 'Multisuite no puede comunicarse correctamente con Komga. No es un error de contraseña: comprueba que Lectura haya terminado de arrancar y actualiza el estado.',
                });
                unset($this->setupState);

                return;
            }
            if (! $this->existingAccount) {
                app(KomgaSession::class)->prepare($user, $this->password);
            }
            $this->toast('Lectura conectada. Las cuentas se prepararán automáticamente al entrar en Multisuite.');
            $this->redirectRoute($this->existingAccount ? 'komga.confirm' : 'services.open', $this->existingAccount ? [] : ['service' => 'komga']);
        });
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Conectar Lectura" description="Usa tu cuenta de Multisuite. No necesitas inventar otro correo ni otra contraseña." />
    <x-ui::container>
        <x-ui::panel>
            @if ($this->setupState === 'unavailable')
                <p role="status">Multisuite no puede consultar Komga en este momento. Espera a que Lectura termine de arrancar; no necesitas cambiar tu contraseña.</p>
                <x-ui::button wire:click="$refresh" variant="secondary">Actualizar estado</x-ui::button>
            @else
                @if ($this->setupState === 'empty')
                    <p>Komga está vacío. Crearemos su primera cuenta con el correo y la contraseña de tu cuenta de Multisuite.</p>
                @elseif ($this->setupState === 'connected')
                    <p>Ya hay una conexión guardada. Puedes abrir Lectura o volver a autorizarla si cambiaste sus credenciales.</p>
                    <x-ui::button :href="route('services.open', 'komga')" variant="secondary">Abrir Lectura</x-ui::button>
                @else
                    <p>Komga ya está inicializado. Si se preparó automáticamente, prueba tu cuenta de Multisuite. No se creará otro administrador.</p>
                @endif
                <x-ui::form wire:submit="connect">
                    <x-ui::checkbox name="existingAccount" wire:model="existingAccount" label="Ya tenía otra cuenta administradora en Komga" />
                    <div x-show="$wire.existingAccount" x-cloak>
                        <x-ui::field name="email" type="email" label="Correo del administrador de Komga" wire:model="email" x-bind:disabled="!$wire.existingAccount" required autocomplete="username" />
                    </div>
                        <p x-show="!$wire.existingAccount">Se usará tu cuenta: {{ auth()->user()->email }}</p>
                    <x-ui::password name="password" label="Contraseña de la cuenta seleccionada" wire:model="password" required autocomplete="current-password" />
                    <x-ui::button type="submit"><span x-show="!$wire.existingAccount">Usar mi cuenta de Multisuite</span><span x-show="$wire.existingAccount" x-cloak>Conectar cuenta existente</span></x-ui::button>
                </x-ui::form>
            @endif
        </x-ui::panel>
    </x-ui::container>
</div>
