<x-layouts::auth title="Entrar" eyebrow="Bienvenido de nuevo" heading="Entra en Multisuite" description="Usa tu usuario o tu correo y tu contraseña.">
    @if (session('status'))
        <x-ui::notice style="margin-top:18px">{{ session('status') }}</x-ui::notice>
    @endif

    <div style="margin-top:20px">
        <x-passkey-verify :label="__('Entrar con una llave de acceso')" :loading-label="__('Comprobando…')" :separator="__('O entra con tu contraseña')" />
    </div>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <x-ui::field name="email" label="Usuario o correo electrónico" required autofocus autocomplete="username" placeholder="tony o tony@example.com" />
        <x-ui::password name="password" label="Contraseña" required autocomplete="current-password" />
        <x-ui::checkbox name="remember" label="Recordarme en este dispositivo" />
        <x-ui::button type="submit" block data-test="login-button" style="margin-top:22px">Entrar</x-ui::button>
    </form>

    <div class="ui-auth-links">
        @if (Route::has('password.request'))
            <a class="ui-link" href="{{ route('password.request') }}" wire:navigate>¿Has olvidado tu contraseña?</a>
        @endif
        <span class="ui-muted">¿Necesitas acceso? Pídeselo al administrador.</span>
    </div>
</x-layouts::auth>
