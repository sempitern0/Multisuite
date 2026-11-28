{{-- Hands a single-use ticket to a service gateway (App\Support\ServiceLaunch). --}}
<x-layouts::auth :title="'Abriendo '.$name" :eyebrow="$eyebrow" :heading="'Abriendo '.$name" description="Estamos conectando tu sesión.">
    <form id="service-launch" method="post" action="{{ $action }}">
        <input type="hidden" name="ticket" value="{{ $ticket }}">
        <x-ui::button type="submit" block>Continuar a {{ $name }}</x-ui::button>
    </form>
    <x-slot:links><x-ui::link :href="route('dashboard')">Volver a Multisuite</x-ui::link></x-slot:links>
    <script>document.getElementById('service-launch').submit()</script>
</x-layouts::auth>
