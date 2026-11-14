<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        if (auth()->user()->hasEnabledTwoFactorAuthentication()) {
            $this->dispatch('two-factor-enabled');

            return;
        }

        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'No se pudieron preparar los datos. Cierra y vuelve a intentarlo.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();
        $this->dispatch('ui-dialog-close', name: 'two-factor-setup');
    }
}; ?>

<div>
    <x-ui::dialog name="two-factor-setup" x-on:close="$wire.closeModal()"
        :title="$showVerificationStep ? 'Comprueba el código' : 'Activar verificación en dos pasos'"
        :description="$showVerificationStep ? 'Escribe el código de 6 cifras que muestra tu aplicación de autenticación.' : 'Escanea el código QR con tu aplicación de autenticación, o escribe la clave a mano.'">
        @if ($showVerificationStep)
            <div x-data x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                <flux:otp name="code" wire:model="code" length="6" label="Código de verificación" label:sr-only />
            </div>
            @error('code')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
            <x-ui::actions>
                <x-ui::button variant="ghost" wire:click="resetVerification">Atrás</x-ui::button>
                <x-ui::button wire:click="confirmTwoFactor" x-bind:disabled="$wire.code.length < 6">Confirmar</x-ui::button>
            </x-ui::actions>
        @else
            @error('setupData')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
            @if ($qrCodeSvg !== '')
                <x-ui::qr label="Código QR para la aplicación de autenticación">{!! $qrCodeSvg !!}</x-ui::qr>
                <x-ui::separator>o escribe la clave a mano</x-ui::separator>
                <x-ui::copy-field :value="$manualSetupKey" label="Clave de configuración" />
            @elseif (! $errors->has('setupData'))
                <x-ui::text tone="muted">Preparando el código…</x-ui::text>
            @endif
            <x-ui::actions>
                <x-ui::button variant="ghost" wire:click="closeModal">Cancelar</x-ui::button>
                <x-ui::button wire:click="showVerificationIfNecessary" :disabled="$qrCodeSvg === ''">Continuar</x-ui::button>
            </x-ui::actions>
        @endif
    </x-ui::dialog>
</div>
