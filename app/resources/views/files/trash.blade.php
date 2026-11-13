<x-layouts::app :title="__('Papelera')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <nav aria-label="Ubicación" class="text-sm text-zinc-600 dark:text-zinc-300">
            <a href="{{ route('files.index') }}" class="underline-offset-4 hover:underline">Mis archivos</a> <span aria-hidden="true">/</span> Papelera
        </nav>
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-1">
                <flux:heading size="xl">Papelera</flux:heading>
                <flux:text>Los archivos se borran definitivamente a los {{ $days }} días. Mientras están aquí siguen ocupando {{ \App\Support\Bytes::format($bytes) }} de tu espacio.</flux:text>
            </div>
            @if ($entries->isNotEmpty())
                <form method="POST" action="{{ route('files.trash.empty') }}" onsubmit="return confirm('¿Vaciar la papelera? Los archivos y sus versiones se borrarán definitivamente.')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger">Vaciar papelera</flux:button>
                </form>
            @endif
        </header>
        @if (session('status'))
            <p role="status" class="rounded-lg border border-teal-300 p-4 dark:border-teal-800">{{ session('status') }}</p>
        @endif
        @error('file_purge')
            <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">{{ $message }}</p>
        @enderror
        <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Archivos en la papelera">
            @forelse ($entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                    <a href="{{ route('files.show', $entry) }}" class="min-w-0 truncate underline-offset-4 hover:underline">{{ $entry->name }}</a>
                    <div class="flex flex-wrap items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <span>{{ \App\Support\Bytes::format((int) $entry->size) }} · {{ $entry->trashed_at?->format('d/m/Y') }}</span>
                        <form method="POST" action="{{ route('files.restore', ['fileId' => $entry->id]) }}">
                            @csrf
                            <button type="submit" class="underline" aria-label="Restaurar {{ $entry->name }}">Restaurar</button>
                        </form>
                        <form method="POST" action="{{ route('files.purge', ['fileId' => $entry->id]) }}" onsubmit="return confirm('¿Borrar definitivamente? No se puede deshacer.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-700 underline dark:text-red-400" aria-label="Borrar definitivamente {{ $entry->name }}">Borrar</button>
                        </form>
                    </div>
                </li>
            @empty
                <li class="p-5 text-zinc-600 dark:text-zinc-300">La papelera está vacía.</li>
            @endforelse
        </ul>
    </div>
</x-layouts::app>
