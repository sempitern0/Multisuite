<x-layouts::auth title="Confirmar contraseña" eyebrow="Zona protegida" heading="Confirma que eres tú" description="Antes de continuar, introduce de nuevo tu contraseña.">
    <div style="margin-top:20px">
        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirmar con una llave de acceso')"
            :loading-label="__('Comprobando…')"
            :separator="__('O confirma con tu contraseña')"
        />
    </div>

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <x-ui::password name="password" label="Contraseña" required autofocus autocomplete="current-password" />
        <x-ui::button type="submit" block data-test="confirm-password-button" style="margin-top:22px">Continuar</x-ui::button>
    </form>
</x-layouts::auth>
