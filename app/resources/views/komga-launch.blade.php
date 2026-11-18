<x-layouts::auth title="Abriendo Lectura" eyebrow="Tu biblioteca" heading="Abriendo Lectura" description="Estamos conectando tu sesión.">
    <form id="komga-launch" method="post" action="{{ $action }}">
        <input type="hidden" name="ticket" value="{{ $ticket }}">
        <x-ui::button type="submit" block>Continuar a Lectura</x-ui::button>
    </form>
    <x-slot:links><x-ui::link :href="route('dashboard')">Volver a Multisuite</x-ui::link></x-slot:links>
    <script>document.getElementById('komga-launch').submit()</script>
</x-layouts::auth>
