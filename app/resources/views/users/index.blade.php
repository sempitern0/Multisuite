<x-layouts::app :title="__('Usuarios')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="xl">Usuarios</flux:heading>
            <flux:button :href="route('users.create')" variant="primary">Crear usuario</flux:button>
        </div>
        @if (session('status'))
            <p role="status" class="rounded-lg border p-4">{{ session('status') }}</p>
        @endif
        <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-end gap-3">
            <flux:input name="q" label="Buscar por nombre, usuario o correo" :value="$query" maxlength="100" />
            <flux:button type="submit">Buscar</flux:button>
            @if ($query !== '')
                <flux:link :href="route('users.index')">Limpiar búsqueda</flux:link>
            @endif
        </form>
        <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Usuarios de Multisuite">
            @forelse ($users as $listedUser)
                <li class="flex flex-wrap items-center justify-between gap-4 p-5">
                    <div class="min-w-0 space-y-1">
                        <p class="break-words text-lg font-semibold">{{ $listedUser->name }}</p>
                        <p class="break-all">{{ $listedUser->email }}</p>
                        @if ($listedUser->username)
                            <flux:text>{{ '@'.$listedUser->username }}</flux:text>
                        @endif
                        <flux:text>{{ $listedUser->roleLabel() }} · {{ $listedUser->must_change_password ? 'Debe cambiar su contraseña' : ($listedUser->hasVerifiedEmail() ? 'Correo verificado' : 'Correo pendiente de verificar') }}</flux:text>
                    </div>
                    @can('update', $listedUser)
                        <flux:button :href="route('users.edit', $listedUser)" :aria-label="'Gestionar a '.$listedUser->name">Gestionar</flux:button>
                    @endcan
                </li>
            @empty
                <li class="p-5">No hay usuarios que coincidan con la búsqueda.</li>
            @endforelse
        </ul>
        {{ $users->links() }}
    </div>
</x-layouts::app>
