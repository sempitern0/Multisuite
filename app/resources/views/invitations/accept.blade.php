<x-layouts::auth :title="__('Aceptar invitación')">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Te damos la bienvenida" description="Completa tus datos para acceder a Multisuite." />
        <div class="space-y-2">
            <p class="break-all">{{ $invitation->email }}</p>
            <flux:text>Rol: {{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }}.</flux:text>
        </div>
        @foreach ($invitation->services ?? [] as $service)
            <flux:text>{{ config('portal.services.'.$service.'.name', $service) }} se preparará con tu usuario (o correo si no eliges uno) y esta contraseña.</flux:text>
        @endforeach
        <form method="POST" action="{{ request()->fullUrl() }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="name" label="Nombre" :value="old('name')" required autofocus autocomplete="name" />
            <flux:input name="username" label="Nombre de usuario (opcional)" :value="old('username')" autocomplete="username" minlength="3" maxlength="32" />
            <flux:text>De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo. También lo usarás en Música.</flux:text>
            <flux:input name="password" type="password" label="Contraseña" required autocomplete="new-password" viewable />
            <flux:input name="password_confirmation" type="password" label="Confirmar contraseña" required autocomplete="new-password" viewable />
            @error('email') <p role="alert">{{ $message }}</p> @enderror
            @error('role') <p role="alert">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary">Crear mi cuenta</flux:button>
        </form>
    </div>
</x-layouts::auth>
