<x-layouts::app title="Cambiar correo">
    <x-pages::settings.layout heading="Cambiar correo de recuperación" subheading="Tu correo actual seguirá activo hasta que confirmes el nuevo.">
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::form :action="route('email-change.request')">
                    <x-ui::field name="email" type="email" label="Nuevo correo" autocomplete="email" required />
                    <x-ui::button type="submit">Enviar enlace de confirmación</x-ui::button>
                </x-ui::form>
                <x-ui::link :href="auth()->user()->hasVerifiedEmail() ? route('security.edit') : route('profile.edit')">Cancelar</x-ui::link>
            </x-ui::stack>
        </x-ui::panel>
    </x-pages::settings.layout>
</x-layouts::app>
