<x-layouts::app :title="__('Actividad administrativa')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-6">
        <flux:heading size="xl">Actividad administrativa</flux:heading>
        <flux:text>Altas manuales, cambios y bajas de usuarios realizadas desde el panel. Fechas en {{ config('app.timezone') }}.</flux:text>
        <form method="GET" action="{{ route('audit.index') }}" class="flex flex-wrap items-end gap-3">
            <flux:select name="action" label="Acción">
                <flux:select.option value="" :selected="$action === ''">Todas</flux:select.option>
                <flux:select.option value="user.created" :selected="$action === 'user.created'">Creación</flux:select.option>
                <flux:select.option value="user.updated" :selected="$action === 'user.updated'">Modificación</flux:select.option>
                <flux:select.option value="user.deleted" :selected="$action === 'user.deleted'">Eliminación</flux:select.option>
            </flux:select>
            <flux:button type="submit">Filtrar</flux:button>
        </form>
        <ul class="divide-y rounded-xl border border-zinc-200 dark:border-zinc-700" aria-label="Historial de actividad">
            @forelse ($events as $event)
                <li class="space-y-2 p-5">
                    <p class="font-semibold">{{ ['user.created' => 'Usuario creado', 'user.updated' => 'Usuario modificado', 'user.deleted' => 'Usuario eliminado'][$event->action] ?? 'Acción administrativa' }}</p>
                    <p class="break-words">{{ $event->actor_name }} (#{{ $event->actor_id }}) → {{ $event->subject_name }} (#{{ $event->subject_id }})</p>
                    <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('d/m/Y H:i:s') }}</time>
                    @if (! empty($event->details['fields']))
                        <flux:text>Campos modificados: {{ implode(', ', array_map(fn ($field) => ['name' => 'nombre', 'email' => 'correo', 'role' => 'rol'][$field] ?? $field, $event->details['fields'])) }}.</flux:text>
                    @endif
                    @if (($event->details['previous_role'] ?? null) !== null && $event->details['previous_role'] !== $event->details['role'])
                        <flux:text>Rol: {{ $event->details['previous_role'] }} → {{ $event->details['role'] }}.</flux:text>
                    @endif
                </li>
            @empty
                <li class="p-5">Todavía no hay actividad registrada para este filtro.</li>
            @endforelse
        </ul>
        {{ $events->links() }}
    </div>
</x-layouts::app>
