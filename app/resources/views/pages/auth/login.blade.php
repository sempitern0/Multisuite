<x-layouts::auth :title="__('Entrar')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Bienvenido a Multisuite')" :description="__('Entra con tu usuario o correo.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Usuario o correo electrónico')"
                :value="old('email')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="tony o tony@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Contraseña')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Contraseña')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('¿Has olvidado tu contraseña?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Recordarme')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Entrar') }}
                </flux:button>
            </div>
        </form>

        <flux:text>¿Necesitas acceso? Contacta con el administrador.</flux:text>
    </div>
</x-layouts::auth>
