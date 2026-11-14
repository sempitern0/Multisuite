<x-layouts::auth title="Guardar contraseña" eyebrow="Nueva contraseña" heading="Elige tu nueva contraseña" description="Usa una contraseña que no utilices en otros sitios.">
    <x-ui::form :action="route('password.update')">
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <x-ui::field name="email" type="email" label="Correo electrónico" :value="request('email')" required autocomplete="email" />
        <x-ui::password name="password" label="Nueva contraseña" required autocomplete="new-password" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />
        <x-ui::password name="password_confirmation" label="Repite la contraseña" required autocomplete="new-password" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />
        <x-ui::button type="submit" block data-test="reset-password-button">Guardar contraseña</x-ui::button>
    </x-ui::form>
</x-layouts::auth>
