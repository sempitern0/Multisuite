<x-layouts::auth :title="__('Confirmar contraseña')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirmar contraseña')"
            :description="__('Introduce tu contraseña para continuar.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Contraseña')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Contraseña')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Continuar') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
