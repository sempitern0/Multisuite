<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Livewire\Concerns\ForgetsSecrets;
use App\Models\User;
use App\Services\Storage\StorageReservations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    use ForgetsSecrets, PasswordValidationRules;

    public string $password = '';

    /** Deletes the signed-in person's own account after confirming their password. */
    public function deleteUser(Logout $logout): void
    {
        $this->forgetting(['password'], fn () => $this->validate(['password' => $this->currentPasswordRules()]));

        DB::transaction(function (): void {
            $user = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            app(StorageReservations::class)->prepareUserDeletion($user);
            $user->delete();
        });
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<x-ui::panel tone="danger" aria-labelledby="delete-account">
    <x-ui::stack gap="sm">
        <x-ui::heading id="delete-account">Eliminar mi cuenta</x-ui::heading>
        <x-ui::text>Se borran tu cuenta y tus archivos de Multisuite. No se puede deshacer.</x-ui::text>
        <x-ui::button variant="danger" icon="trash" data-test="delete-user-button" x-on:click="$dispatch('ui-dialog-open', 'delete-account')">Eliminar mi cuenta</x-ui::button>
    </x-ui::stack>

    <x-ui::dialog name="delete-account" title="¿Eliminar tu cuenta?" description="Todos tus datos de Multisuite se borrarán definitivamente. Escribe tu contraseña para confirmarlo.">
        <x-ui::form wire:submit="deleteUser">
            <x-ui::password name="password" label="Contraseña" wire:model="password" required autocomplete="current-password" />
            @error('deletion')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
            <x-ui::actions>
                <x-ui::button variant="ghost" x-on:click="$dispatch('ui-dialog-close', 'delete-account')">Cancelar</x-ui::button>
                <x-ui::button type="submit" variant="danger" data-test="confirm-delete-user-button">Eliminar mi cuenta</x-ui::button>
            </x-ui::actions>
        </x-ui::form>
    </x-ui::dialog>
</x-ui::panel>
