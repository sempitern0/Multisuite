<x-layouts::app title="Confirmar correo">
    <x-ui::panel>
        <x-ui::heading>Confirmar cambio de correo</x-ui::heading>
        <x-ui::text>Usarás {{ $email }} para entrar y recuperar tu cuenta.</x-ui::text>
        <x-ui::form :action="request()->fullUrl()"><x-ui::button type="submit">Confirmar correo</x-ui::button></x-ui::form>
    </x-ui::panel>
</x-layouts::app>
