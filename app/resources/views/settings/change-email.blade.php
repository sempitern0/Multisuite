<x-layouts::app title="Cambiar correo">
    <x-ui::panel>
        <x-ui::heading>Cambiar correo de recuperación</x-ui::heading>
        <x-ui::text>Tu correo actual seguirá activo hasta que confirmes el nuevo.</x-ui::text>
        <x-ui::form :action="route('email-change.request')">
            <x-ui::field name="email" type="email" label="Nuevo correo" autocomplete="email" required />
            <x-ui::button type="submit">Enviar enlace de confirmación</x-ui::button>
        </x-ui::form>
    </x-ui::panel>
</x-layouts::app>
