<?php

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Seguridad')] class extends Component {
    use ForgetsSecrets, NotifiesInterface;

    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        $this->forgetting(['current_password', 'password', 'password_confirmation'], function (): void {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
            app(\App\Services\ServiceAccounts::class)->changePassword(Auth::user(), $validated['password']);
        });

        $this->toast('Contraseña actualizada');
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /** Removes one of the person's own passkeys (someone else's id is "not found"). */
    public function removePasskey(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $deletePasskey(auth()->user(), auth()->user()->passkeys()->findOrFail($passkeyId));
        $this->loadPasskeys();
        $this->toast('Llave de acceso eliminada');
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
        $this->toast('Verificación en dos pasos activada');
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
        $this->toast('Verificación en dos pasos desactivada');
    }
}; ?>

<div>
    <x-pages::settings.layout heading="Seguridad" subheading="Elige cómo entrar en tu cuenta y dónde recuperar el acceso.">
        <x-ui::stack gap="sm">
            <x-ui::security-option wire:key="security-password" title="Cambiar contraseña" icon="key"
                description="Actualiza la contraseña de Multisuite y de tus servicios vinculados.">
                <x-ui::form wire:submit="updatePassword">
                    <x-ui::password name="current_password" label="Contraseña actual" wire:model="current_password" required autocomplete="current-password" />
                    <x-ui::password name="password" label="Nueva contraseña" wire:model="password" required autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        help="También se actualizará en los servicios vinculados a tu cuenta, como Música." />
                    <x-ui::password name="password_confirmation" label="Repite la nueva contraseña" wire:model="password_confirmation" required autocomplete="new-password" />
                    <x-ui::button type="submit" data-test="update-password-button">Guardar contraseña</x-ui::button>
                </x-ui::form>
            </x-ui::security-option>

            @if ($canManageTwoFactor)
                <x-ui::security-option wire:key="security-two-factor" title="Verificación en dos pasos" icon="shield"
                    description="Confirma el acceso con una aplicación de autenticación."
                    :status="$twoFactorEnabled ? 'Activada' : 'Desactivada'" :tone="$twoFactorEnabled ? 'ok' : 'muted'">
                    <x-ui::stack gap="md">
                        @if ($twoFactorEnabled)
                            <x-ui::text>Al entrar se te pedirá, además de la contraseña, un código de la aplicación de autenticación de tu teléfono.</x-ui::text>
                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                            <x-ui::confirm title="¿Desactivar la verificación en dos pasos?">
                                <x-slot:trigger><x-ui::button variant="danger">Desactivar verificación en dos pasos</x-ui::button></x-slot:trigger>
                                Al entrar con contraseña dejarás de necesitar el código de la aplicación.
                                <x-slot:action><x-ui::button variant="danger" wire:click="disable">Desactivar</x-ui::button></x-slot:action>
                            </x-ui::confirm>
                        @else
                            <x-ui::text>Añade un segundo paso al entrar: además de la contraseña, un código de una aplicación de autenticación en tu teléfono.</x-ui::text>
                            <x-ui::button icon="shield" x-on:click="$dispatch('ui-dialog-open', 'two-factor-setup'); $dispatch('start-two-factor-setup')">Activar verificación en dos pasos</x-ui::button>
                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        @endif
                    </x-ui::stack>
                </x-ui::security-option>
            @endif

            @if ($canManagePasskeys)
                <x-ui::security-option wire:key="security-passkeys" title="Llaves de acceso" icon="fingerprint"
                    description="Entra con la huella, la cara o el PIN de tu dispositivo."
                    :status="count($passkeys) === 1 ? '1 llave' : count($passkeys).' llaves'">
                    <x-ui::stack gap="md">
                        @if ($passkeys === [])
                            <x-ui::text>Todavía no tienes llaves de acceso. Añade una en un dispositivo de confianza.</x-ui::text>
                        @else
                            <x-ui::table label="Llaves de acceso">
                                <x-slot:head><tr><th>Llave</th><th>Añadida</th><th>Último uso</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                                @foreach ($passkeys as $passkey)
                                    <tr wire:key="passkey-{{ $passkey['id'] }}">
                                        <x-ui::td primary :sub="$passkey['authenticator']">{{ $passkey['name'] }}</x-ui::td>
                                        <x-ui::td label="Añadida">{{ $passkey['created_at_diff'] }}</x-ui::td>
                                        <x-ui::td label="Último uso">{{ $passkey['last_used_at_diff'] ?? 'Sin usar' }}</x-ui::td>
                                        <x-ui::td align="end">
                                            <x-ui::confirm title="¿Eliminar esta llave de acceso?">
                                                <x-slot:trigger><x-ui::button variant="ghost" size="sm" icon="trash" :aria-label="'Eliminar la llave '.$passkey['name']">Eliminar</x-ui::button></x-slot:trigger>
                                                Ya no podrás entrar con «{{ $passkey['name'] }}».
                                                <x-slot:action><x-ui::button variant="danger" wire:click="removePasskey({{ $passkey['id'] }})">Eliminar llave</x-ui::button></x-slot:action>
                                            </x-ui::confirm>
                                        </x-ui::td>
                                    </tr>
                                @endforeach
                            </x-ui::table>
                        @endif
                        <x-passkey-registration />
                    </x-ui::stack>
                </x-ui::security-option>
            @endif

            <x-ui::security-option wire:key="security-email" title="Correo de recuperación" icon="mail"
                :description="auth()->user()->email" :status="auth()->user()->hasVerifiedEmail() ? 'Verificado' : 'Sin verificar'"
                :tone="auth()->user()->hasVerifiedEmail() ? 'ok' : 'warn'">
                <x-ui::stack gap="sm">
                    <x-ui::text>Si olvidas tu contraseña, te enviaremos el enlace a este correo.</x-ui::text>
                    <x-ui::link :href="route('email-change.edit')">Cambiar correo de recuperación</x-ui::link>
                </x-ui::stack>
            </x-ui::security-option>
        </x-ui::stack>
    </x-pages::settings.layout>
</div>
