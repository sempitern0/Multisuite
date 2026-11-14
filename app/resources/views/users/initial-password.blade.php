<x-layouts::auth title="Configurar contraseña personal" eyebrow="Primer acceso" heading="Elige tu nueva contraseña" description="Cambia la contraseña temporal para continuar.">
    <form method="POST" action="{{ route('password.initial.update') }}">
        @csrf
        <x-ui::password name="current_password" label="Contraseña actual" required autocomplete="current-password" />
        <x-ui::password name="password" label="Nueva contraseña" required autocomplete="new-password" />
        <x-ui::password name="password_confirmation" label="Repite la nueva contraseña" required autocomplete="new-password" />
        <x-ui::button type="submit" block style="margin-top:22px">Guardar y continuar</x-ui::button>
    </form>
    <form method="POST" action="{{ route('logout') }}" style="margin-top:10px">
        @csrf
        <x-ui::button type="submit" variant="ghost" block>Cerrar sesión</x-ui::button>
    </form>
</x-layouts::auth>
