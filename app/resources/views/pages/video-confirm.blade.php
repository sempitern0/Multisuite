<?php

use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\JellyfinAccounts;
use App\Services\ServiceAccounts;
use App\Services\VideoSession;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Vídeo needs the portal password once to open (or renew) the person's Jellyfin session, for
 * example after signing in with a passkey or when Jellyfin was down at sign-in.
 */
new #[Title('Abrir Vídeo')] class extends Component {
    use ForgetsSecrets, ThrottlesActions;

    public string $password = '';

    public function boot(): void
    {
        abort_unless(config('portal.services.video.enabled') && app(ServiceAccounts::class)->entitled(Auth::user(), 'video'), 403);
    }

    public function open(): void
    {
        $this->throttle('account-change');
        $this->forgetting(['password'], function (): void {
            $this->validate(['password' => ['required', 'string', 'current_password']]);
            app(VideoSession::class)->prepare(Auth::user(), $this->password);
            if (! session()->has('video.login')) {
                $this->addError('password', 'No hemos podido preparar Vídeo. Tu cuenta de Multisuite sigue disponible; vuelve a intentarlo en unos minutos.');

                return;
            }
            $this->redirectRoute('services.open', ['service' => 'video']);
        });
    }
}; ?>

<div>
    <x-ui::page-header :back="route('dashboard')" back-label="Servicios" title="Abrir Vídeo" description="Confirma tu contraseña de Multisuite para entrar en tus vídeos sin escribir otra vez tu usuario. No necesitas crear otra cuenta." />
    <x-ui::container>
        <x-ui::panel>
            @if (! JellyfinAccounts::connected())
                <p>El propietario debe conectar Vídeo desde Administración una sola vez. Tu cuenta se preparará automáticamente después.</p>
            @else
                <x-ui::form wire:submit="open">
                    <x-ui::password name="password" label="Contraseña actual" wire:model="password" required autocomplete="current-password" />
                    <x-ui::button type="submit">Abrir Vídeo</x-ui::button>
                </x-ui::form>
            @endif
        </x-ui::panel>
    </x-ui::container>
</div>
