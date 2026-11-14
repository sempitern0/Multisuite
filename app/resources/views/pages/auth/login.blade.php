<x-layouts::auth title="Entrar" eyebrow="Bienvenido de nuevo" heading="Entra en Multisuite" description="Usa tu usuario o tu correo y tu contraseña.">
    @if (session('status'))
        <x-ui::notice>{{ session('status') }}</x-ui::notice>
    @endif

    <x-passkey-verify :label="__('Entrar con una llave de acceso')" :loading-label="__('Comprobando…')" :separator="__('O entra con tu contraseña')" />

    <x-ui::form :action="route('login.store')">
        <x-ui::field name="email" label="Usuario o correo electrónico" required autofocus autocomplete="username" placeholder="tony o tony@example.com" />
        <x-ui::password name="password" label="Contraseña" required autocomplete="current-password" />
        <x-ui::checkbox name="remember" label="Recordarme en este dispositivo" />
        <x-ui::button type="submit" block data-test="login-button">Entrar</x-ui::button>
    </x-ui::form>

    <x-slot:links>
        @if (Route::has('password.request'))
            <x-ui::link :href="route('password.request')" wire:navigate>¿Has olvidado tu contraseña?</x-ui::link>
        @endif
        <x-ui::text tone="muted">¿Necesitas acceso? Pídeselo al administrador.</x-ui::text>
    </x-slot:links>
</x-layouts::auth>
