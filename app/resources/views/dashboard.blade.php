<x-layouts::app title="Servicios">
    <x-ui::page-header eyebrow="Tu espacio personal" title="¿Qué te apetece hoy?" description="Abre tus servicios y tus archivos desde aquí." />

    <section aria-label="Servicios" class="ui-service-grid">
        @foreach ($tiles as $tile)
            <x-ui::service-tile :name="$tile['name']" :icon="$tile['icon']" :status="$tile['status']" :status-label="$tile['label']" :href="$tile['href']">
                @if ($tile['hint'] || $tile['username'])
                    <span class="ui-muted" style="word-break:break-all">
                        {{ $tile['hint'] }}
                        @if ($tile['username']) Usuario: {{ $tile['username'] }} @endif
                    </span>
                @endif
            </x-ui::service-tile>
        @endforeach
        <x-ui::service-tile name="Archivos" icon="folder" status-label="Disponible" :href="route('files.index')" />
    </section>

    @if ($storage)
        <x-ui::panel style="margin-top:24px;max-width:560px" aria-labelledby="my-storage">
            <h2 id="my-storage" class="ui-h2">Tu almacenamiento</h2>
            @php($percent = \App\Support\Bytes::percent($storage['used'], $storage['limit']))
            <div class="ui-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Espacio usado">
                <span style="width: {{ $percent }}%"></span>
            </div>
            <p class="ui-sub" style="font-size:13px">Has usado {{ \App\Support\Bytes::format($storage['used']) }} de {{ \App\Support\Bytes::format($storage['limit']) }}.</p>
            <a class="ui-link" href="{{ route('files.index') }}">Ir a Mis archivos</a>
        </x-ui::panel>
    @endif
</x-layouts::app>
