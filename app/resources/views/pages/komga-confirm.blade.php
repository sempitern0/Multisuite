<?php

use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\KomgaAccounts;
use App\Services\KomgaSession;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Abrir Lectura')] class extends Component {
    use ForgetsSecrets, ThrottlesActions;

    public string $password = '';

    public function boot(): void
    {
        abort_unless(config('portal.services.komga.enabled') && app(ServiceAccounts::class)->entitled(Auth::user(), 'komga'), 403);
    }

    public function open(): void
    {
        $this->throttle('account-change');
        $this->forgetting(['password'], function (): void {
            $this->validate(['password' => ['required', 'string', 'current_password']]);
            app(KomgaSession::class)->prepare(Auth::user(), $this->password);
            if (! session()->has('komga.login')) {
                $this->addError('password', KomgaAccounts::connected()
                    ? 'No hemos podido preparar Lectura. Tu cuenta de Multisuite sigue disponible; vuelve a intentarlo en unos minutos.'
                    : 'El propietario debe conectar Lectura desde Administración una sola vez.');

                return;
            }
            $this->redirectRoute('services.open', ['service' => 'komga']);
        });
    }
}; ?>

<div>
    <x-ui::page-header :back="route('dashboard')" back-label="Servicios" title="Abrir Lectura" description="Confirma tu contraseña de Multisuite para preparar o renovar el acceso a tus libros. No necesitas crear otra cuenta." />
    <x-ui::container>
        <x-ui::panel>
            <x-ui::form wire:submit="open">
                <x-ui::password name="password" label="Contraseña actual" wire:model="password" required autocomplete="current-password" />
                <x-ui::button type="submit">Abrir Lectura</x-ui::button>
            </x-ui::form>
        </x-ui::panel>
    </x-ui::container>
</div>
