<x-layouts::app :title="$file->name">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <nav aria-label="Ubicación" class="flex flex-wrap items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300">
            <a href="{{ route('files.index') }}" class="underline-offset-4 hover:underline">Mis archivos</a>
            @foreach ($breadcrumbs as $crumb)
                <span aria-hidden="true">/</span>
                <a href="{{ route('files.folder', $crumb) }}" class="underline-offset-4 hover:underline">{{ $crumb->name }}</a>
            @endforeach
        </nav>
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <flux:heading size="xl" class="break-words">{{ $file->name }}</flux:heading>
                @if ($error === null)
                    <flux:text>{{ \App\Support\Bytes::format($size) }} · {{ $mime }}</flux:text>
                @endif
            </div>
            @if ($error === null)
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('files.content', ['fileId' => $file->id, 'download' => 1])" variant="primary">Descargar</flux:button>
                    @if ($kind !== 'none')
                        <flux:button :href="route('files.content', ['fileId' => $file->id])" target="_blank" rel="noopener">Abrir en pestaña nueva</flux:button>
                    @endif
                </div>
            @endif
        </header>

        @if (session('status'))
            <p role="status" class="rounded-lg border border-teal-300 p-4 dark:border-teal-800">{{ session('status') }}</p>
        @endif

        <section aria-label="Vista previa" class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            @if ($error !== null)
                <p role="alert" class="p-5 text-red-700 dark:text-red-400">{{ $error }} Avisa al responsable técnico si el problema continúa.</p>
            @elseif ($kind === 'image')
                <img src="{{ route('files.content', ['fileId' => $file->id]) }}" alt="{{ $file->name }}" class="mx-auto max-h-[75vh] w-auto bg-zinc-50 dark:bg-zinc-900">
            @elseif ($kind === 'pdf')
                <iframe src="{{ route('files.content', ['fileId' => $file->id]) }}" title="Vista previa de {{ $file->name }}" class="h-[75vh] w-full"></iframe>
            @elseif ($html !== null)
                <article class="max-w-none p-6 leading-7 [&_a]:underline [&_code]:rounded [&_code]:bg-zinc-100 [&_code]:px-1 dark:[&_code]:bg-zinc-800 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:ms-5 [&_ol]:list-decimal [&_p]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-zinc-100 [&_pre]:p-4 dark:[&_pre]:bg-zinc-800 [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_ul]:list-disc">{!! $html !!}</article>
            @elseif ($text !== null)
                <pre class="max-h-[75vh] overflow-auto whitespace-pre-wrap break-words p-5 text-sm">{{ $text }}</pre>
                @if ($truncated)
                    <p class="border-t border-zinc-200 p-4 text-sm dark:border-zinc-700">Vista previa limitada a {{ \App\Support\Bytes::format((int) config('nas.preview_text_max_bytes')) }}. Descarga el archivo para verlo completo.</p>
                @endif
            @else
                <p class="p-5 text-zinc-700 dark:text-zinc-300">Este tipo de archivo no tiene vista previa. Puedes descargarlo y abrirlo con tu programa habitual.</p>
            @endif
        </section>

        <form method="POST" action="{{ route('files.update', ['fileId' => $file->id]) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            @method('PATCH')
            <flux:input name="name" label="Renombrar" :value="old('name', $file->name)" maxlength="200" required />
            <flux:button type="submit">Guardar nombre</flux:button>
            @error('file_name')
                <p role="alert" class="w-full text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </form>
    </div>
</x-layouts::app>
