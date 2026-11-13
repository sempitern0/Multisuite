@php($fmt = fn (?int $bytes): string => \App\Support\Bytes::format($bytes))
@php($segmentClass = ['portal' => 'bg-teal-600', 'other' => 'bg-zinc-500', 'safety' => 'bg-amber-400', 'free' => 'bg-zinc-200 dark:bg-zinc-700'])
<x-layouts::app :title="__('Almacenamiento')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 py-6">
        <header class="space-y-2">
            <flux:heading size="xl">Almacenamiento</flux:heading>
            <flux:text>Decide cuánto espacio puede ocupar cada persona. No hace falta tocar discos ni particiones: el servidor ya tiene preparados los discos y aquí solo repartes el espacio.</flux:text>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-lg border border-teal-300 p-4 dark:border-teal-800">{{ session('status') }}</p>
        @endif
        @if ($errors->hasAny(['preset', 'amount', 'unit']))
            <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">{{ $errors->first('preset') ?: ($errors->first('amount') ?: $errors->first('unit')) }}</p>
        @endif

        <section aria-labelledby="how-it-works" class="space-y-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading id="how-it-works">Cómo funciona</flux:heading>
            <ul class="list-disc space-y-1 ps-5 text-zinc-700 dark:text-zinc-300">
                <li><strong>Discos:</strong> el límite de cada disco es lo máximo que el portal guardará en él. Aunque pongas un límite mayor, nunca se escribe más allá del espacio libre real menos un margen de seguridad.</li>
                <li><strong>Personas:</strong> cada una tiene un máximo personal, sume lo que sume en todos los discos. Es un techo, no un espacio apartado: lo que no usa sigue libre para los demás.</li>
                <li><strong>Seguridad:</strong> si un disco se llena, se desconecta o hay dudas sobre su espacio, el portal deja de aceptar contenido nuevo en él. Nunca borra archivos para hacer sitio.</li>
            </ul>
        </section>

        {{-- Disks --}}
        <section aria-labelledby="disks" class="space-y-4">
            <flux:heading id="disks" size="lg">Discos</flux:heading>
            @if ($inventoryError)
                <p role="alert" class="rounded-lg border border-red-300 p-4 text-red-700 dark:border-red-800 dark:text-red-400">La configuración de discos del servidor tiene un error: {{ $inventoryError }} Pide al responsable técnico que revise <code>NAS_STORAGE_VOLUMES</code> (ver docs/STORAGE.md).</p>
            @elseif (! $configured && $disks === [])
                <div class="space-y-2 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">
                    <p class="font-semibold">Todavía no hay discos preparados.</p>
                    <flux:text>Por seguridad, el portal no puede elegir carpetas ni discos por sí mismo. El responsable técnico debe conectarlos una vez en el servidor siguiendo docs/STORAGE.md. Después aparecerán aquí y podrás repartir su espacio.</flux:text>
                </div>
            @endif

            @if ($virtualHost && $disks !== [])
                <div role="note" class="space-y-1 rounded-lg bg-amber-50 p-4 text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <p class="font-semibold">Capacidad del disco virtual de Docker Desktop</p>
                    <p class="text-sm">Este portal funciona dentro de Docker Desktop. La capacidad que ves es la del disco virtual de Docker (suele llegar a 1 TB), no la de tu disco físico. Los archivos sí ocupan espacio real en el disco del ordenador, así que fija un límite menor que el espacio libre de ese disco. En un servidor Linux con los discos montados directamente, estas cifras son exactas.</p>
                </div>
            @endif

            @foreach ($disks as $disk)
                <article class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="disk-{{ $disk['id'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 id="disk-{{ $disk['id'] }}" class="text-lg font-semibold">{{ $disk['id'] }}</h3>
                        <span @class(['rounded-full px-3 py-1 text-sm', 'bg-teal-100 text-teal-900 dark:bg-teal-900 dark:text-teal-100' => $disk['status'] === 'observed', 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200' => $disk['status'] !== 'observed'])>{{ $disk['statusLabel'] }}</span>
                    </div>
                    @if ($disk['locations'] !== [])
                        <flux:text>Carpetas en este disco: {{ implode(', ', $disk['locations']) }}.</flux:text>
                    @endif

                    @if ($disk['segments'] !== [])
                        <div>
                            <div class="flex h-4 w-full overflow-hidden rounded-full" role="img" aria-label="{{ 'Disco de '.$fmt($disk['total']).': '.collect($disk['segments'])->map(fn ($segment) => $segment['label'].' '.$fmt($segment['bytes']))->implode(', ') }}">
                                @foreach ($disk['segments'] as $segment)
                                    <div class="{{ $segmentClass[$segment['key']] }}" style="width: {{ $segment['percent'] }}%"></div>
                                @endforeach
                            </div>
                            <ul class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-zinc-700 dark:text-zinc-300">
                                @foreach ($disk['segments'] as $segment)
                                    <li class="flex items-center gap-2"><span class="inline-block size-3 rounded-sm {{ $segmentClass[$segment['key']] }}" aria-hidden="true"></span>{{ $segment['label'] }}: {{ $fmt($segment['bytes']) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-zinc-500">Límite del portal</dt><dd class="text-base font-medium">{{ $disk['limit'] === null ? 'Sin configurar (no admite contenido)' : $fmt($disk['limit']) }}</dd></div>
                        <div><dt class="text-zinc-500">Usado por personas</dt><dd class="text-base font-medium">{{ $fmt($disk['used']) }}@if ($disk['reserved'] > 0) <span class="text-zinc-500">+ {{ $fmt($disk['reserved']) }} en curso</span>@endif</dd></div>
                        <div><dt class="text-zinc-500">Máximo recomendado</dt><dd class="text-base font-medium">{{ $disk['suggested'] === null ? 'Desconocido' : $fmt($disk['suggested']) }}</dd></div>
                    </dl>

                    @if ($disk['staleCount'] > 0)
                        <div role="status" class="space-y-1 rounded-lg bg-zinc-100 p-3 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                            <p>{{ $disk['staleCount'] }} {{ $disk['staleCount'] === 1 ? 'subida interrumpida sigue' : 'subidas interrumpidas siguen' }} ocupando {{ $fmt($disk['staleAutomatic'] + $disk['staleManual']) }} del espacio de sus propietarios.</p>
                            @if ($disk['staleAutomatic'] > 0)
                                <p class="text-sm">{{ $fmt($disk['staleAutomatic']) }} se liberarán solos: la limpieza automática comprueba cada 10 minutos que no quedan restos en el disco antes de devolver el espacio.</p>
                            @endif
                            @if ($disk['staleManual'] > 0)
                                <p class="text-sm">{{ $fmt($disk['staleManual']) }} no tienen limpieza automática. Pide al responsable técnico que los revise (docs/STORAGE.md, «Reconciliación»). No se liberan a ciegas para no perder la cuenta de archivos que podrían seguir en el disco.</p>
                            @endif
                        </div>
                    @endif

                    @if ($disk['limitAboveCapacity'])
                        <p role="alert" class="rounded-lg bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-200">El límite es mayor que el espacio realmente disponible. No es peligroso (el portal comprueba el espacio real antes de aceptar contenido), pero las personas podrían quedarse sin sitio antes de llegar a su máximo.</p>
                    @endif

                    @if ($canManagePools && $disk['editable'])
                        <form method="POST" action="{{ route('storage.pools.update', $disk['id']) }}" class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            @csrf
                            @method('PUT')
                            @php($poolOptions = $disk['suggested'] !== null && $disk['suggested'] > 0 ? [$disk['suggested'] => 'Todo lo recomendado ('.$fmt($disk['suggested']).')', 0 => 'No usar este disco'] : [0 => 'No usar este disco'])
                            <x-storage.size-picker :presets="$presets" :units="$units" :selected="$disk['limit']" label="Límite del portal en este disco" :extra="$poolOptions" />
                            @error('pool_'.$disk['id'])
                                <p role="alert" class="text-red-700 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <flux:button type="submit" variant="primary">Guardar límite del disco</flux:button>
                        </form>
                    @elseif (! $canManagePools)
                        <flux:text>Solo el propietario puede cambiar el límite de los discos.</flux:text>
                    @endif
                </article>
            @endforeach
        </section>

        {{-- Summary and default --}}
        <section aria-labelledby="sharing" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading id="sharing" size="lg">Reparto</flux:heading>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-zinc-500">Límite total de discos</dt><dd class="text-base font-medium">{{ $fmt($poolLimitTotal) }}</dd></div>
                <div><dt class="text-zinc-500">Prometido a {{ $userCount }} {{ $userCount === 1 ? 'persona' : 'personas' }}</dt><dd class="text-base font-medium">{{ $fmt($assigned) }}</dd></div>
                <div><dt class="text-zinc-500">Ocupado en total</dt><dd class="text-base font-medium">{{ $fmt($usedTotal) }}</dd></div>
            </dl>
            @if ($equalShare !== null)
                <flux:text>Si repartes por igual, a cada persona le corresponden unos {{ $fmt($equalShare) }}.</flux:text>
            @endif
            @if ($overcommitted)
                <p role="alert" class="rounded-lg bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-200">Has prometido más espacio del que existe. Es habitual (no todo el mundo llena su espacio) y no pone datos en riesgo: cuando el disco se llene, simplemente nadie podrá añadir más hasta liberar espacio o ampliar el disco.</p>
            @endif

            <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <p class="font-semibold">Espacio por defecto para personas nuevas: {{ $fmt($default) }}</p>
                <flux:text>Se aplica a quien todavía no tiene un espacio propio asignado, la primera vez que añade contenido. No cambia a quien ya tiene uno.</flux:text>
                @if ($canManagePools)
                    <form method="POST" action="{{ route('storage.default.update') }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <x-storage.size-picker :presets="$presets" :units="$units" :selected="$default" label="Espacio por defecto" />
                        @error('default')
                            <p role="alert" class="text-red-700 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <flux:button type="submit" variant="primary">Guardar espacio por defecto</flux:button>
                    </form>
                @endif
            </div>
        </section>

        {{-- People --}}
        <section aria-labelledby="people" class="space-y-4">
            <flux:heading id="people" size="lg">Personas</flux:heading>
            <form method="GET" action="{{ route('storage.index') }}" class="flex flex-wrap items-end gap-3">
                <flux:input name="q" label="Buscar por nombre, usuario o correo" :value="$query" maxlength="100" />
                <flux:button type="submit">Buscar</flux:button>
                @if ($query !== '')
                    <flux:link :href="route('storage.index')">Limpiar búsqueda</flux:link>
                @endif
            </form>
            <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Espacio por persona">
                @forelse ($rows as $row)
                    <li class="space-y-3 p-5" id="user-{{ $row['user']->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="break-words text-lg font-semibold">{{ $row['user']->name }}</p>
                            <flux:text>{{ ['owner' => 'Propietario', 'admin' => 'Administrador', 'member' => 'Miembro'][$row['user']->role] ?? 'Sin rol' }}</flux:text>
                        </div>
                        <div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $row['percent'] }}" aria-label="Espacio usado por {{ $row['user']->name }}">
                                <div @class(['h-full', 'bg-teal-600' => $row['percent'] < 90, 'bg-red-600' => $row['percent'] >= 90]) style="width: {{ $row['percent'] }}%"></div>
                            </div>
                            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $fmt($row['used']) }} usados de {{ $fmt($row['limit']) }}@if ($row['reserved'] > 0) ({{ $fmt($row['reserved']) }} subiendo)@endif
                                @if ($row['followsDefault']) · usa el espacio por defecto @endif
                                @if ($row['limit'] === 0) · no puede añadir contenido @endif
                            </p>
                        </div>
                        @if ($row['canManage'])
                            <form method="POST" action="{{ route('storage.users.update', $row['user']) }}" class="flex flex-wrap items-end gap-3">
                                @csrf
                                @method('PUT')
                                <x-storage.size-picker :presets="$presets" :units="$units" :selected="$row['followsDefault'] ? null : $row['limit']" />
                                <flux:button type="submit" :aria-label="'Guardar espacio de '.$row['user']->name">Guardar</flux:button>
                            </form>
                            @error('quota_'.$row['user']->id)
                                <p role="alert" class="text-red-700 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        @endif
                    </li>
                @empty
                    <li class="p-5">No hay personas que coincidan con la búsqueda.</li>
                @endforelse
            </ul>
            {{ $users->links() }}
        </section>
    </div>
</x-layouts::app>
