<x-layouts::app :title="__('Invitaciones')">
    <div class="mx-auto w-full max-w-3xl space-y-8">
        <div>
            <flux:heading size="xl">Invitaciones</flux:heading>
            <flux:text>Invita a tu familia y conocidos. Cada enlace caduca en 7 días y solo sirve para una cuenta.</flux:text>
        </div>
        @if (session('status'))
            <p role="status" class="rounded-lg border p-4">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('invitations.store') }}" class="space-y-4">
            @csrf
            <flux:input name="email" type="email" label="Correo de la persona invitada" :value="old('email')" required autocomplete="email" />
            <flux:select name="role" label="Rol de la cuenta" required>
                @foreach ($roles as $role)
                    <flux:select.option :value="$role" :selected="old('role', 'member') === $role">{{ $role === 'admin' ? 'Administrador' : 'Miembro' }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>Los administradores pueden invitar miembros. Solo el propietario puede invitar administradores.</flux:text>
            <flux:button type="submit" variant="primary">Enviar invitación</flux:button>
        </form>
        <section class="space-y-4" aria-label="Invitaciones emitidas">
            <flux:heading size="lg">Invitaciones emitidas</flux:heading>
            @forelse ($invitations as $invitation)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-300 p-4 dark:border-zinc-700">
                    <div class="min-w-0">
                        <p class="break-all">{{ $invitation->email }}</p>
                        <flux:text>{{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }} · {{ $invitation->used_at ? 'Aceptada' : ($invitation->expires_at->isPast() ? 'Caducada o revocada' : 'Pendiente') }}</flux:text>
                    </div>
                    @if (! $invitation->used_at && $invitation->expires_at->isFuture())
                        <form method="POST" action="{{ route('invitations.revoke', $invitation) }}">
                            @csrf
                            @method('DELETE')
                            <flux:button type="submit" :aria-label="'Revocar invitación de '.$invitation->email">Revocar</flux:button>
                        </form>
                    @endif
                </div>
            @empty
                <flux:text>Aún no hay invitaciones.</flux:text>
            @endforelse
            {{ $invitations->links() }}
        </section>
    </div>
</x-layouts::app>
