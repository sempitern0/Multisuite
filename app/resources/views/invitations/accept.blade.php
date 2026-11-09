<x-layouts::auth :title="__('Aceptar invitación')">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Te damos la bienvenida" description="Completa tus datos para acceder a Multisuite." />
        <div class="space-y-2">
            <p class="break-all">{{ $invitation->email }}</p>
            <flux:text>Rol asignado: {{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }}. El correo y el rol los determina tu invitación.</flux:text>
        </div>
        @foreach ($invitation->services ?? [] as $service)
            <flux:text>{{ config('portal.services.'.$service.'.name', $service) }} se preparará con tu correo y esta contraseña.</flux:text>
        @endforeach
        <form method="POST" action="{{ request()->fullUrl() }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="name" label="Nombre" :value="old('name')" required autofocus autocomplete="name" />
            <flux:input name="password" type="password" label="Contraseña" required autocomplete="new-password" viewable />
            <flux:input name="password_confirmation" type="password" label="Confirmar contraseña" required autocomplete="new-password" viewable />
            @error('email') <p role="alert">{{ $message }}</p> @enderror
            @error('role') <p role="alert">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary">Crear mi cuenta</flux:button>
        </form>
    </div>
</x-layouts::auth>
