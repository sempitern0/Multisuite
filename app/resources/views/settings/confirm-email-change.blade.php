<x-layouts::app title="Confirmar correo">
    <x-pages::settings.layout heading="Confirmar cambio de correo" subheading="Usarás esta dirección para entrar y recuperar tu cuenta.">
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::text>{{ $email }}</x-ui::text>
                <x-ui::form :action="request()->fullUrl()"><x-ui::button type="submit">Confirmar correo</x-ui::button></x-ui::form>
                <x-ui::link :href="auth()->user()->hasVerifiedEmail() ? route('security.edit') : route('profile.edit')">Cancelar</x-ui::link>
            </x-ui::stack>
        </x-ui::panel>
    </x-pages::settings.layout>
</x-layouts::app>
