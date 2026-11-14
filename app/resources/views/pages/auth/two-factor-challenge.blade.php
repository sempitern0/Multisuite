<x-layouts::auth title="Código de seguridad" eyebrow="Verificación en dos pasos" heading="Introduce tu código">
    <div
        x-cloak
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            code: '',
            recovery_code: '',
            focusOtp() {
                this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
            },
            init() {
                if (! this.showRecoveryInput) {
                    this.focusOtp();
                }
            },
            toggleInput() {
                this.showRecoveryInput = ! this.showRecoveryInput;
                this.code = '';
                this.recovery_code = '';
                $nextTick(() => {
                    this.showRecoveryInput ? this.$refs.recovery_code?.focus() : this.focusOtp();
                });
            },
        }"
    >
        <x-ui::stack>
            <x-ui::text x-show="! showRecoveryInput">Escribe el código de 6 cifras de tu aplicación de autenticación.</x-ui::text>
            <x-ui::text x-show="showRecoveryInput">Escribe uno de tus códigos de recuperación.</x-ui::text>

            <x-ui::form :action="route('two-factor.login.store')">
                <div x-show="! showRecoveryInput" x-ref="otp">
                    <flux:otp x-model="code" length="6" name="code" label="Código de verificación" label:sr-only />
                    @error('code')<x-ui::notice tone="bad">{{ $message }}</x-ui::notice>@enderror
                </div>
                <div x-show="showRecoveryInput">
                    <x-ui::field name="recovery_code" label="Código de recuperación" x-ref="recovery_code" x-bind:required="showRecoveryInput" autocomplete="one-time-code" x-model="recovery_code" />
                </div>
                <x-ui::button type="submit" block>Continuar</x-ui::button>
            </x-ui::form>

            <x-ui::link x-on:click="toggleInput()">
                <span x-show="! showRecoveryInput">Usar un código de recuperación</span>
                <span x-show="showRecoveryInput">Usar la aplicación de autenticación</span>
            </x-ui::link>
        </x-ui::stack>
    </div>
</x-layouts::auth>
