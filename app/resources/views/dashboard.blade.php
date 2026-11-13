<x-layouts::app :title="__('Multisuite')">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 py-6">
        <header class="space-y-3">
            <p class="text-sm font-medium uppercase tracking-widest text-teal-700 dark:text-teal-300">Tu espacio personal</p>
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">¿Qué te apetece hoy?</h1>
            <p class="max-w-xl text-zinc-600 dark:text-zinc-300">Abre tus aplicaciones y disfruta de tu biblioteca.</p>
        </header>
        @php($accounts = \App\Models\ServiceAccount::where('user_id', auth()->id())->get()->keyBy('service'))
        <section aria-label="Aplicaciones" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse (config('portal.services', []) as $id => $service)
                <article class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-teal-700 dark:text-teal-300">{{ $service['category'] }}</p>
                    <h2 class="text-xl font-semibold">{{ $service['name'] }}</h2>
                    <p class="flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $service['description'] }}</p>
                    @if ($account = $accounts->get($id))
                        <div class="space-y-1 text-base leading-6">
                            <p role="status" class="text-zinc-700 dark:text-zinc-200">
                                @if ($account->status === 'ready') Disponible
                                @elseif ($account->status === 'pending') Preparando tu cuenta.
                                @elseif ($account->status === 'expired') Actualiza tu contraseña en Ajustes para reintentar.
                                @else Contacta con el administrador para activar el acceso.
                                @endif
                            </p>
                            <p class="break-all text-zinc-600 dark:text-zinc-400">Usuario: {{ $account->username }}</p>
                        </div>
                    @endif
                    @if (auth()->user()->service_access !== null && config('provisioning.'.$id.'.enabled') && ! in_array($id, auth()->user()->service_access, true))
                        <p class="text-sm">Este servicio no está incluido en tu invitación.</p>
                    @elseif (auth()->user()->service_access !== null && config('provisioning.'.$id.'.enabled') && $accounts->get($id)?->status !== 'ready')
                        <button type="button" disabled class="cursor-not-allowed rounded-lg bg-zinc-200 px-4 py-3 text-center text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Cuenta pendiente de preparación</button>
                        <a href="{{ route('dashboard') }}" class="text-sm underline">Actualizar estado</a>
                    @elseif ($service['enabled'] && filled($service['url']))
                        <a href="{{ route('services.open', $id) }}" class="rounded-lg bg-teal-800 px-4 py-3 text-center font-medium text-white hover:bg-teal-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">Abrir {{ $service['name'] }}</a>
                    @else
                        <p class="rounded-lg bg-zinc-100 px-4 py-3 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Todavía no configurada</p>
                    @endif
                </article>
            @empty
                <p class="text-zinc-600 dark:text-zinc-300">Aún no hay aplicaciones configuradas.</p>
            @endforelse
        </section>
        @php($quota = \App\Models\UserStorageQuota::find(auth()->id()))
        @php($limit = (int) ($quota?->limit_bytes ?? \App\Models\StorageSetting::defaultUserQuotaBytes()))
        @if ($limit > 0)
            @php($used = (int) ($quota?->used_bytes ?? 0))
            <section aria-labelledby="my-storage" class="max-w-xl space-y-2 rounded-2xl border border-zinc-200 p-6 dark:border-zinc-700">
                <h2 id="my-storage" class="text-lg font-semibold">Tu almacenamiento</h2>
                <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ \App\Support\Bytes::percent($used, $limit) }}" aria-label="Espacio usado">
                    <div class="h-full bg-teal-600" style="width: {{ \App\Support\Bytes::percent($used, $limit) }}%"></div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">Has usado {{ \App\Support\Bytes::format($used) }} de {{ \App\Support\Bytes::format($limit) }}.</p>
            </section>
        @endif
    </main>
</x-layouts::app>
