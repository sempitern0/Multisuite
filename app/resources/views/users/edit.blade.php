<x-layouts::app :title="__('Gestionar usuario')">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 py-6">
        <flux:link :href="route('users.index')">Volver a usuarios</flux:link>
        <flux:heading size="xl">Gestionar a {{ $managedUser->name }}</flux:heading>
        @if (session('status'))
            <p role="status" class="rounded-lg border p-4">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('users.update', $managedUser) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <flux:input name="name" label="Nombre" :value="old('name', $managedUser->name)" required maxlength="255" />
            <flux:input name="email" type="email" label="Correo" :value="old('email', $managedUser->email)" required maxlength="255" />
            <flux:checkbox name="email_confirmed" value="1" label="Si cambio el correo, he comprobado que pertenece a esta persona" :checked="(bool) old('email_confirmed')" />
            <flux:select name="role" label="Rol" required>
                @foreach ($roles as $role)
                    <flux:select.option :value="$role" :selected="old('role', $managedUser->role) === $role">{{ $role === 'admin' ? 'Administrador' : 'Miembro' }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>Nombre de usuario: {{ $managedUser->username ?? 'No elegido' }}. El nombre de acceso a Música se conserva. La persona puede recuperar su contraseña desde el acceso a Multisuite.</flux:text>
            <flux:button type="submit" variant="primary">Guardar cambios</flux:button>
        </form>
        @if ($accounts->isNotEmpty())
            <section class="space-y-2" aria-labelledby="linked-services">
                <flux:heading id="linked-services">Servicios vinculados</flux:heading>
                @foreach ($accounts as $account)
                    <p>{{ config('portal.services.'.$account->service.'.name', $account->service) }} · {{ $account->username }} · {{ $account->status === 'ready' ? 'Preparada' : 'Pendiente de preparación' }}</p>
                @endforeach
            </section>
        @endif
        @can('view-storage')
            @php($quota = \App\Models\UserStorageQuota::find($managedUser->id))
            @php($limit = $quota?->limit_bytes ?? \App\Models\StorageSetting::defaultUserQuotaBytes())
            <section class="space-y-2" aria-labelledby="storage-space">
                <flux:heading id="storage-space">Espacio de almacenamiento</flux:heading>
                <flux:text>{{ \App\Support\Bytes::format((int) ($quota?->used_bytes ?? 0)) }} usados de {{ \App\Support\Bytes::format((int) $limit) }}{{ $quota === null ? ' (espacio por defecto)' : '' }}.</flux:text>
                <flux:link :href="route('storage.index', ['q' => $managedUser->email]).'#user-'.$managedUser->id">Cambiar su espacio</flux:link>
            </section>
        @endcan
        <section class="space-y-4 rounded-xl border border-red-300 p-5 dark:border-red-800" aria-labelledby="delete-account">
            <flux:heading id="delete-account">Eliminar usuario</flux:heading>
            <flux:text>Esta acción es permanente. Elimina la cuenta de Multisuite y su cuenta vinculada de Música, incluidas sus listas y datos personales. Los archivos de la biblioteca musical se conservan.</flux:text>
            <form method="POST" action="{{ route('users.destroy', $managedUser) }}" class="space-y-4">
                @csrf
                @method('DELETE')
                <flux:input name="confirm_email" label="Escribe el correo de esta cuenta para confirmar" :value="old('confirm_email')" autocomplete="off" required />
                @error('deletion')
                    <p role="alert" class="text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
                <flux:button type="submit" variant="danger">Eliminar usuario definitivamente</flux:button>
            </form>
        </section>
    </div>
</x-layouts::app>
