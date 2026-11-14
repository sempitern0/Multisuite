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
    <x-pages::settings.layout heading="Seguridad" subheading="Contraseña, verificación en dos pasos y llaves de acceso.">
        <x-ui::stack gap="lg">
            <x-ui::panel aria-labelledby="password-heading">
                <x-ui::stack gap="md">
                    <x-ui::heading id="password-heading">Cambiar contraseña</x-ui::heading>
                    <x-ui::form wire:submit="updatePassword">
                        <x-ui::password name="current_password" label="Contraseña actual" wire:model="current_password" required autocomplete="current-password" />
                        <x-ui::password name="password" label="Nueva contraseña" wire:model="password" required autocomplete="new-password"
                            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                            help="También se actualizará en los servicios vinculados a tu cuenta, como Música." />
                        <x-ui::password name="password_confirmation" label="Repite la nueva contraseña" wire:model="password_confirmation" required autocomplete="new-password" />
                        <x-ui::button type="submit" data-test="update-password-button">Guardar contraseña</x-ui::button>
                    </x-ui::form>
                </x-ui::stack>
            </x-ui::panel>

            @if ($canManageTwoFactor)
                <x-ui::panel aria-labelledby="two-factor-heading">
                    <x-ui::stack gap="md">
                        <x-ui::row>
                            <x-ui::heading id="two-factor-heading">Verificación en dos pasos</x-ui::heading>
                            <x-ui::status :tone="$twoFactorEnabled ? 'ok' : 'muted'">{{ $twoFactorEnabled ? 'Activada' : 'Desactivada' }}</x-ui::status>
                        </x-ui::row>
                        @if ($twoFactorEnabled)
                            <x-ui::text>Al entrar se te pedirá, además de la contraseña, un código de la aplicación de autenticación de tu teléfono.</x-ui::text>
                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                            <x-ui::confirm title="¿Desactivar la verificación en dos pasos?">
                                <x-slot:trigger><x-ui::button variant="danger">Desactivar verificación en dos pasos</x-ui::button></x-slot:trigger>
                                Tu cuenta quedará protegida solo por la contraseña. Podrás activarla de nuevo cuando quieras.
                                <x-slot:action><x-ui::button variant="danger" wire:click="disable">Desactivar</x-ui::button></x-slot:action>
                            </x-ui::confirm>
                        @else
                            <x-ui::text>Añade un segundo paso al entrar: además de la contraseña, un código de una aplicación de autenticación en tu teléfono.</x-ui::text>
                            <x-ui::button icon="shield" x-on:click="$dispatch('ui-dialog-open', 'two-factor-setup'); $dispatch('start-two-factor-setup')">Activar verificación en dos pasos</x-ui::button>
                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        @endif
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            @if ($canManagePasskeys)
                <x-ui::stack gap="md" aria-labelledby="passkeys-heading">
                    <x-ui::heading id="passkeys-heading">Llaves de acceso</x-ui::heading>
                    <x-ui::text>Entra sin contraseña con la huella, la cara o el PIN de tu dispositivo.</x-ui::text>
                    <x-ui::table label="Llaves de acceso">
                        <x-slot:head><tr><th>Llave</th><th>Añadida</th><th>Último uso</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                        @forelse ($passkeys as $passkey)
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
                        @empty
                            <x-ui::table-empty colspan="4">Todavía no tienes llaves de acceso.</x-ui::table-empty>
                        @endforelse
                    </x-ui::table>
                    <x-passkey-registration />
                </x-ui::stack>
            @endif
        </x-ui::stack>
    </x-pages::settings.layout>
</div>
