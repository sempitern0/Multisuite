<x-layouts::auth title="Confirmar contraseña" eyebrow="Un último paso" heading="Confirma tu contraseña" description="Para continuar, necesitamos comprobar que eres tú.">
    <x-passkey-verify
        options-route="passkey.confirm-options"
        submit-route="passkey.confirm"
        :label="__('Confirmar con una llave de acceso')"
        :loading-label="__('Comprobando…')"
        :separator="__('O confirma con tu contraseña')"
    />

    <x-ui::form :action="route('password.confirm.store')">
        <x-ui::password name="password" label="Contraseña actual" required autofocus autocomplete="current-password" />
        <x-ui::button type="submit" block data-test="confirm-password-button">Confirmar y continuar</x-ui::button>
    </x-ui::form>

    @if (Route::has('password.request'))
        <x-slot:links>
            <x-ui::link :href="route('dashboard')">Cancelar</x-ui::link>
            <x-ui::link :href="route('password.request')">¿La has olvidado?</x-ui::link>
        </x-slot:links>
    @endif
</x-layouts::auth>
