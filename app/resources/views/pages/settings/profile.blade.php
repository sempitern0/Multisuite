<?php

use App\Actions\Users\SetUsername;
use App\Concerns\ProfileValidationRules;
use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Perfil')] class extends Component {
    use ForgetsSecrets, NotifiesInterface, ProfileValidationRules, ThrottlesActions;

    public string $name = '';

    public string $email = '';

    public string $username = '';

    public string $current_password = '';

    public bool $verificationSent = false;

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();
        $validated = $this->validate($this->profileRules($user->id));
        $user->fill($validated);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();
        $this->toast('Perfil actualizado');
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();
        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }
        $user->sendEmailVerificationNotification();
        $this->verificationSent = true;
    }

    public function saveUsername(SetUsername $setUsername): void
    {
        $this->throttle('account-change');
        $this->forgetting(['current_password'], function () use ($setUsername): void {
            $data = $this->validate($setUsername->rules());
            $setUsername->handle(Auth::user(), $data['username'], $data['current_password']);
        });
        $this->reset('username');
        $this->toast('Nombre de usuario guardado. Consulta el estado de Música en Servicios');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return ! Auth::user()->hasVerifiedEmail();
    }
}; ?>

<div>
    <x-pages::settings.layout heading="Perfil" subheading="Tu nombre, tu correo y tu nombre de usuario.">
        <x-ui::stack gap="lg">
            <x-ui::panel>
                <x-ui::form wire:submit="updateProfileInformation">
                    <x-ui::field name="name" label="Nombre" wire:model="name" required autocomplete="name" />
                    <x-ui::field name="email" type="email" label="Correo electrónico" wire:model="email" required autocomplete="email" />
                    @if ($this->hasUnverifiedEmail)
                        <x-ui::notice tone="warn">
                            Tu correo está sin verificar.
                            <x-ui::link wire:click="resendVerificationNotification">Reenviar el correo de verificación</x-ui::link>
                        </x-ui::notice>
                        @if ($verificationSent)
                            <x-ui::notice>Te hemos enviado un nuevo enlace de verificación.</x-ui::notice>
                        @endif
                    @endif
                    <x-ui::button type="submit" data-test="update-profile-button">Guardar</x-ui::button>
                </x-ui::form>
            </x-ui::panel>

            <x-ui::panel aria-labelledby="username-heading">
                <x-ui::stack gap="md">
                    <x-ui::heading id="username-heading">Nombre de usuario</x-ui::heading>
                    @if (auth()->user()->username)
                        <x-ui::text><strong>{{ auth()->user()->username }}</strong> · Puedes entrar a Multisuite con este usuario o con tu correo.</x-ui::text>
                    @else
                        <x-ui::text tone="muted">Se elige una sola vez. Tu cuenta de Música conservará sus datos y pasará a usar este usuario cuando termine la sincronización.</x-ui::text>
                        <x-ui::form wire:submit="saveUsername">
                            <x-ui::field name="username" label="Nombre de usuario" wire:model="username" required minlength="3" maxlength="32" autocomplete="username"
                                help="De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo." />
                            <x-ui::password name="current_password" label="Contraseña actual" wire:model="current_password" required autocomplete="current-password" />
                            <x-ui::button type="submit" variant="secondary">Guardar usuario</x-ui::button>
                        </x-ui::form>
                    @endif
                </x-ui::stack>
            </x-ui::panel>

            @if (! $this->hasUnverifiedEmail)
                <livewire:pages::settings.delete-user-modal />
            @endif
        </x-ui::stack>
    </x-pages::settings.layout>
</div>
