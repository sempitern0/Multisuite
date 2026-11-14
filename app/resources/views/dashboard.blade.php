<x-layouts::app title="Servicios">
    <x-ui::page-header eyebrow="Tu espacio personal" title="Tus servicios" description="Abre tus servicios y tus archivos desde aquí." />

    <x-ui::stack gap="lg">
        <x-ui::service-grid label="Servicios">
            @foreach ($tiles as $tile)
                <x-ui::service-tile :name="$tile['name']" :icon="$tile['icon']" :status="$tile['status']" :status-label="$tile['label']" :href="$tile['href']" :note="$tile['hint']" :detail="$tile['username'] ? 'Usuario: '.$tile['username'] : null" />
            @endforeach
            <x-ui::service-tile name="Archivos" icon="folder" status-label="Disponible" :href="route('files.index')" />
        </x-ui::service-grid>

        @if ($storage)
            <x-ui::panel aria-labelledby="my-storage">
                <x-ui::stack gap="sm">
                    <x-ui::heading id="my-storage">Tu almacenamiento</x-ui::heading>
                    <x-ui::meter :value="\App\Support\Bytes::percent($storage['used'], $storage['limit'])" label="Espacio usado">
                        Has usado {{ \App\Support\Bytes::format($storage['used']) }} de {{ \App\Support\Bytes::format($storage['limit']) }}.
                    </x-ui::meter>
                    <x-ui::link :href="route('files.index')">Ir a Mis archivos</x-ui::link>
                </x-ui::stack>
            </x-ui::panel>
        @endif
    </x-ui::stack>
</x-layouts::app>
