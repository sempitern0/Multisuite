<x-layouts::auth title="Abriendo Vídeo" eyebrow="Tus vídeos" heading="Abriendo Vídeo" description="Estamos conectando tu sesión.">
    <form id="video-launch" method="post" action="{{ $action }}">
        <input type="hidden" name="ticket" value="{{ $ticket }}">
        <x-ui::button type="submit" block>Continuar a Vídeo</x-ui::button>
    </form>
    <x-slot:links><x-ui::link :href="route('dashboard')">Volver a Multisuite</x-ui::link></x-slot:links>
    <script>document.getElementById('video-launch').submit()</script>
</x-layouts::auth>
