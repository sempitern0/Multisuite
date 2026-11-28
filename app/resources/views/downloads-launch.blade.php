<x-layouts::auth title="Abriendo Descargas" eyebrow="Descargas" heading="Abriendo Descargas" description="Estamos conectando tu sesión.">
    <form id="downloads-launch" method="post" action="{{ $action }}">
        <input type="hidden" name="ticket" value="{{ $ticket }}">
        <x-ui::button type="submit" block>Continuar a Descargas</x-ui::button>
    </form>
    <x-slot:links><x-ui::link :href="route('dashboard')">Volver a Multisuite</x-ui::link></x-slot:links>
    <script>document.getElementById('downloads-launch').submit()</script>
</x-layouts::auth>
