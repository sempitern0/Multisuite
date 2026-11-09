<x-layouts::app :title="__('Crear usuario')">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 py-6">
        <div class="space-y-2">
            <flux:heading size="xl">Crear cuenta manualmente</flux:heading>
            <flux:text>Para personas que no pueden recibir una invitación. El administrador facilita una contraseña temporal; la persona deberá cambiarla antes de utilizar Multisuite. No se envía correo.</flux:text>
        </div>
        @if (session('status'))
            <p role="status" class="rounded-lg border p-4">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('users.store') }}" class="space-y-5">
            @csrf
            <flux:input name="name" label="Nombre" :value="old('name')" required autocomplete="off" />
            <flux:input name="email" type="email" label="Correo de acceso" :value="old('email')" required autocomplete="off" />
            <flux:select name="role" label="Rol" required>
                @foreach ($roles as $role)
                    <flux:select.option :value="$role" :selected="old('role', 'member') === $role">{{ $role === 'admin' ? 'Administrador' : 'Miembro' }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>El rol de propietario solo se asigna desde la consola. Los administradores únicamente pueden crear miembros.</flux:text>
            @foreach ($services as $service)
                <flux:checkbox name="services[]" :value="$service" :label="config('portal.services.'.$service.'.name')" :checked="in_array($service, old('services', $services), true)" />
            @endforeach
            <flux:input name="password" type="password" label="Contraseña temporal" required autocomplete="new-password" viewable />
            <flux:input name="password_confirmation" type="password" label="Confirmar contraseña temporal" required autocomplete="new-password" viewable />
            <flux:text>El correo se considera verificado por intervención del administrador. Confirma su titularidad antes de entregar las credenciales. El acceso a servicios seleccionados se preparará al cambiar la contraseña.</flux:text>
            <flux:button type="submit" variant="primary">Crear cuenta</flux:button>
        </form>
    </div>
</x-layouts::app>
