<x-layouts::auth title="Recuperar contraseña" eyebrow="Recuperar acceso" heading="¿Has olvidado tu contraseña?" description="Te enviaremos un enlace para elegir una nueva.">
    @if (session('status'))
        <x-ui::notice style="margin-top:18px">{{ session('status') }}</x-ui::notice>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-ui::field name="email" type="email" label="Correo electrónico" required autofocus autocomplete="email" placeholder="email@example.com" />
        <x-ui::button type="submit" block data-test="email-password-reset-link-button" style="margin-top:22px">Enviar enlace</x-ui::button>
    </form>

    <div class="ui-auth-links">
        <a class="ui-link" href="{{ route('login') }}" wire:navigate>Volver a iniciar sesión</a>
    </div>
</x-layouts::auth>
