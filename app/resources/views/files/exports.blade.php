<x-layouts::app :title="__('Descargas ZIP')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <nav aria-label="Ubicación" class="text-sm text-zinc-600 dark:text-zinc-300">
            <a href="{{ route('files.index') }}" class="underline-offset-4 hover:underline">Mis archivos</a> <span aria-hidden="true">/</span> Descargas ZIP
        </nav>
        <header class="space-y-1">
            <flux:heading size="xl">Descargas ZIP</flux:heading>
            <flux:text>Cada ZIP se puede descargar durante {{ (int) config('nas.export_ttl_hours') }} horas y después se borra solo. No ocupa espacio de tu cuota.</flux:text>
        </header>
        @if (session('status'))
            <p role="status" class="rounded-lg border border-teal-300 p-4 dark:border-teal-800">{{ session('status') }}</p>
        @endif
        @error('export')
            <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">{{ $message }}</p>
        @enderror
        <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Descargas ZIP">
            @forelse ($exports as $export)
                <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ $export->name }}</p>
                        <flux:text>
                            {{ $export->item_count }} {{ $export->item_count === 1 ? 'archivo' : 'archivos' }} ·
                            @switch($export->state)
                                @case('queued') En cola… @break
                                @case('building') Preparando… @break
                                @case('ready') {{ \App\Support\Bytes::format((int) $export->bytes) }} · disponible hasta {{ $export->expires_at?->format('d/m/Y H:i') }} @break
                                @default No se pudo generar: {{ $export->error }}
                            @endswitch
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($export->state === 'ready')
                            <flux:button :href="route('files.exports.download', ['exportId' => $export->id])" variant="primary">Descargar</flux:button>
                        @endif
                        @if (in_array($export->state, ['ready', 'failed'], true))
                            <form method="POST" action="{{ route('files.exports.destroy', ['exportId' => $export->id]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm underline" aria-label="Eliminar {{ $export->name }}">Eliminar</button>
                            </form>
                        @endif
                    </div>
                </li>
            @empty
                <li class="p-5 text-zinc-600 dark:text-zinc-300">No tienes descargas ZIP. Elige archivos o carpetas en Mis archivos y pulsa «Descargar en ZIP».</li>
            @endforelse
        </ul>
    </div>
    @if ($pending)
        <script>setTimeout(() => window.location.reload(), 5000);</script>
    @endif
</x-layouts::app>
