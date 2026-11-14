<x-layouts::auth title="Recuperar contraseña" eyebrow="Recuperar acceso" heading="¿Has olvidado tu contraseña?" description="Te enviaremos un enlace para elegir una nueva.">
    @if (session('status'))
        <x-ui::notice>{{ session('status') }}</x-ui::notice>
    @endif

    <x-ui::form :action="route('password.email')">
        <x-ui::field name="email" type="email" label="Correo electrónico" required autofocus autocomplete="email" placeholder="email@example.com" />
        <x-ui::button type="submit" block data-test="email-password-reset-link-button">Enviar enlace</x-ui::button>
    </x-ui::form>

    <x-slot:links>
        <x-ui::link :href="route('login')" wire:navigate>Volver a iniciar sesión</x-ui::link>
    </x-slot:links>
</x-layouts::auth>
