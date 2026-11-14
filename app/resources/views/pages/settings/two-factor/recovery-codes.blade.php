<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Load the recovery codes so the user can view them.
     */
    public function viewRecoveryCodes(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'No se pudieron cargar los códigos de recuperación.');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div x-data="{ shown: false }">
    <x-ui::stack gap="sm">
        <x-ui::heading :level="3">Códigos de recuperación</x-ui::heading>
        <x-ui::text tone="muted">Sirven para entrar si pierdes el teléfono. Guárdalos en un gestor de contraseñas; cada uno funciona una sola vez.</x-ui::text>
        <x-ui::toolbar>
            <x-ui::button variant="secondary" icon="eye" x-show="! shown" wire:click="viewRecoveryCodes" x-on:click="shown = true">Ver códigos</x-ui::button>
            <x-ui::button variant="secondary" icon="eye-off" x-show="shown" x-cloak x-on:click="shown = false">Ocultar códigos</x-ui::button>
            @if (filled($recoveryCodes))
                <x-ui::button variant="ghost" x-show="shown" x-cloak wire:click="regenerateRecoveryCodes">Generar códigos nuevos</x-ui::button>
            @endif
        </x-ui::toolbar>
        <div x-show="shown" x-cloak>
            @error('recoveryCodes')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
            @if (filled($recoveryCodes))
                <x-ui::code-list :items="$recoveryCodes" label="Códigos de recuperación" />
            @endif
        </div>
    </x-ui::stack>
</div>
