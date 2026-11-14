<x-layouts::auth title="Verificar correo" eyebrow="Un último paso" heading="Revisa tu correo" description="Abre el enlace que te hemos enviado para activar tu cuenta.">
    @if (session('status') == 'verification-link-sent')
        <x-ui::notice>Te hemos enviado un nuevo enlace.</x-ui::notice>
    @endif

    <x-ui::form :action="route('verification.send')">
        <x-ui::button type="submit" block>Reenviar enlace</x-ui::button>
    </x-ui::form>
    <x-ui::form :action="route('logout')">
        <x-ui::button type="submit" variant="ghost" block data-test="logout-button">Cerrar sesión</x-ui::button>
    </x-ui::form>
</x-layouts::auth>
