<x-layouts::auth title="Aceptar invitación" eyebrow="Invitación" heading="Te damos la bienvenida" description="Completa tus datos para acceder a Multisuite.">
    <x-ui::panel style="margin-top:20px;padding:16px">
        <p style="word-break:break-all;font-weight:650">{{ $invitation->email }}</p>
        <p class="ui-muted">Rol: {{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }}.</p>
        @foreach ($invitation->services ?? [] as $service)
            <p class="ui-muted" style="margin-top:6px">{{ config('portal.services.'.$service.'.name', $service) }} se preparará con tu usuario (o correo si no eliges uno) y esta contraseña.</p>
        @endforeach
    </x-ui::panel>

    @error('email')<x-ui::notice tone="bad" style="margin-top:14px">{{ $message }}</x-ui::notice>@enderror
    @error('role')<x-ui::notice tone="bad" style="margin-top:14px">{{ $message }}</x-ui::notice>@enderror

    <form method="POST" action="{{ request()->fullUrl() }}">
        @csrf
        <x-ui::field name="name" label="Nombre" required autofocus autocomplete="name" />
        <x-ui::field name="username" label="Nombre de usuario (opcional)" autocomplete="username" minlength="3" maxlength="32"
            help="De 3 a 32 caracteres: minúsculas, números, punto, guion o guion bajo. También lo usarás en Música." />
        <x-ui::password name="password" label="Contraseña" required autocomplete="new-password" />
        <x-ui::password name="password_confirmation" label="Repite la contraseña" required autocomplete="new-password" />
        <x-ui::button type="submit" block style="margin-top:22px">Crear mi cuenta</x-ui::button>
    </form>
</x-layouts::auth>
