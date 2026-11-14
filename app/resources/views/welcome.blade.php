<x-layouts::auth title="Multisuite" eyebrow="Bienvenido a Multisuite" heading="Tu espacio digital, siempre a mano." description="Música, archivos y todo lo que necesitas en un único lugar.">
    <x-ui::text>Para crear una cuenta necesitas una invitación del propietario o de un administrador.</x-ui::text>
    @auth
        <x-ui::button :href="route('dashboard')" block>Abrir mis servicios</x-ui::button>
    @else
        <x-ui::button :href="route('login')" block>Iniciar sesión</x-ui::button>
    @endauth
</x-layouts::auth>
