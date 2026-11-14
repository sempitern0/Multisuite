<x-layouts::auth title="Multisuite" eyebrow="Espacio privado" heading="Multisuite" description="Tus servicios, en un espacio privado para compartir.">
    <p class="ui-sub" style="margin-top:14px">Para crear una cuenta necesitas una invitación del propietario o de un administrador.</p>
    <div style="margin-top:22px">
        @auth
            <x-ui::button :href="route('dashboard')" block icon-end="arrow-right">Abrir mis servicios</x-ui::button>
        @else
            <x-ui::button :href="route('login')" block icon-end="arrow-right">Iniciar sesión</x-ui::button>
        @endauth
    </div>
</x-layouts::auth>
