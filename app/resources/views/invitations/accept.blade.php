<x-layouts::auth title="Aceptar invitación" eyebrow="Invitación" heading="Te damos la bienvenida" description="Completa tus datos para acceder a Multisuite.">
    <x-ui::panel>
        <x-ui::stack gap="sm">
            <p><strong>{{ $invitation->email }}</strong></p>
            <x-ui::text tone="muted">Rol: {{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }}.</x-ui::text>
            @foreach ($invitation->services ?? [] as $service)
                <x-ui::text tone="muted">{{ config('portal.services.'.$service.'.name', $service) }} se preparará con tu usuario (o correo si no eliges uno) y esta contraseña.</x-ui::text>
            @endforeach
        </x-ui::stack>
    </x-ui::panel>

    @error('email')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
    @error('role')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror

    <x-ui::form :action="request()->fullUrl()">
        <x-ui::field name="name" label="Nombre" required autofocus autocomplete="name" />
        <x-ui::field name="username" label="Nombre de usuario (opcional)" autocomplete="username" minlength="3" maxlength="32"
            help="De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo. También lo usarás en Música." />
        <x-ui::password name="password" label="Contraseña" required autocomplete="new-password" />
        <x-ui::password name="password_confirmation" label="Repite la contraseña" required autocomplete="new-password" />
        <x-ui::button type="submit" block>Crear mi cuenta</x-ui::button>
    </x-ui::form>
</x-layouts::auth>
