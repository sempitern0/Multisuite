<x-layouts::auth :title="__('Multisuite')">
    <div class="space-y-6">
        <x-auth-header title="Multisuite" description="Tus servicios, en un espacio privado para compartir." />
        <flux:text>Para crear una cuenta necesitas una invitación del propietario o de un administrador.</flux:text>
        @auth
            <flux:button :href="route('dashboard')" variant="primary">Abrir mis aplicaciones</flux:button>
        @else
            <flux:button :href="route('login')" variant="primary">Iniciar sesión</flux:button>
        @endauth
    </div>
</x-layouts::auth>
