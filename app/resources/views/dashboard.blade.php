<x-layouts::app :title="__('Multisuite')">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 py-6">
        <header class="space-y-3">
            <p class="text-sm font-medium uppercase tracking-widest text-teal-700 dark:text-teal-300">Tu espacio personal</p>
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">¿Qué te apetece hoy?</h1>
            <p class="max-w-xl text-zinc-600 dark:text-zinc-300">Abre tus aplicaciones y disfruta de tu biblioteca.</p>
        </header>
        @foreach (\App\Models\ServiceAccount::where('user_id', auth()->id())->get() as $account)
            <p role="status" class="rounded-lg border p-4">
                {{ config('portal.services.'.$account->service.'.name', $account->service) }} · Usuario {{ $account->username }} ·
                @if ($account->status === 'ready') Cuenta preparada.
                @elseif ($account->status === 'pending') Preparando tu cuenta. Volveremos a intentarlo automáticamente.
                @elseif ($account->status === 'expired') La preparación ha caducado. Actualiza tu contraseña en Ajustes para reintentar.
                @else La preparación necesita revisión del administrador.
                @endif
            </p>
        @endforeach
        <section aria-label="Aplicaciones" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse (config('portal.services', []) as $id => $service)
                <article class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-teal-700 dark:text-teal-300">{{ $service['category'] }}</p>
                    <h2 class="text-xl font-semibold">{{ $service['name'] }}</h2>
                    <p class="flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $service['description'] }}</p>
                    @if (auth()->user()->service_access !== null && config('provisioning.'.$id.'.enabled') && ! in_array($id, auth()->user()->service_access, true))
                        <p class="text-sm">Este servicio no está incluido en tu invitación.</p>
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
    </main>
</x-layouts::app>
