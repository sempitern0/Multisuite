<x-layouts::auth title="Configurar contraseña personal" eyebrow="Primer acceso" heading="Elige tu nueva contraseña" description="Cambia la contraseña temporal para continuar.">
    <x-ui::form :action="route('password.initial.update')">
        <x-ui::password name="current_password" label="Contraseña actual" required autocomplete="current-password" />
        <x-ui::password name="password" label="Nueva contraseña" required autocomplete="new-password" />
        <x-ui::password name="password_confirmation" label="Repite la nueva contraseña" required autocomplete="new-password" />
        <x-ui::button type="submit" block>Guardar y continuar</x-ui::button>
    </x-ui::form>
    <x-ui::form :action="route('logout')">
        <x-ui::button type="submit" variant="ghost" block>Cerrar sesión</x-ui::button>
    </x-ui::form>
</x-layouts::auth>
