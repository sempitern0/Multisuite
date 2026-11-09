<x-layouts::auth :title="__('Configurar contraseña personal')">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Configura tu contraseña personal" description="Tu cuenta se ha creado con una contraseña temporal. Debes cambiarla para continuar a Multisuite." />
        <form method="POST" action="{{ route('password.initial.update') }}" class="space-y-5">
            @csrf
            <flux:input name="current_password" type="password" label="Contraseña temporal" required autocomplete="current-password" />
            <flux:input name="password" type="password" label="Nueva contraseña" required autocomplete="new-password" viewable />
            <flux:input name="password_confirmation" type="password" label="Confirmar nueva contraseña" required autocomplete="new-password" viewable />
            <flux:button type="submit" variant="primary">Guardar y continuar</flux:button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="ghost">Cerrar sesión</flux:button>
        </form>
    </div>
</x-layouts::auth>
