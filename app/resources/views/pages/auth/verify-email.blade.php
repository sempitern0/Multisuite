<x-layouts::auth title="Verificar correo" eyebrow="Un último paso" heading="Revisa tu correo" description="Abre el enlace que te hemos enviado para activar tu cuenta.">
    @if (session('status') == 'verification-link-sent')
        <x-ui::notice style="margin-top:18px">Te hemos enviado un nuevo enlace.</x-ui::notice>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" style="margin-top:22px">
        @csrf
        <x-ui::button type="submit" block>Reenviar enlace</x-ui::button>
    </form>
    <form method="POST" action="{{ route('logout') }}" style="margin-top:10px">
        @csrf
        <x-ui::button type="submit" variant="ghost" block data-test="logout-button">Cerrar sesión</x-ui::button>
    </form>
</x-layouts::auth>
