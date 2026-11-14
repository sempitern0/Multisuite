<x-layouts::auth title="Verificación en dos pasos" eyebrow="Verificación en dos pasos" heading="Introduce tu código">
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
        <p class="ui-sub" x-show="! showRecoveryInput">Escribe el código de 6 cifras de tu aplicación de autenticación.</p>
        <p class="ui-sub" x-show="showRecoveryInput">Escribe uno de tus códigos de recuperación.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf
            <div x-show="! showRecoveryInput" x-ref="otp" style="margin-top:22px">
                <flux:otp x-model="code" length="6" name="code" label="Código de verificación" label:sr-only />
            </div>
            <div x-show="showRecoveryInput">
                <div class="ui-field">
                    <label class="ui-label" for="recovery_code">Código de recuperación</label>
                    <input id="recovery_code" class="ui-input" type="text" name="recovery_code" x-ref="recovery_code" x-bind:required="showRecoveryInput" autocomplete="one-time-code" x-model="recovery_code" @error('recovery_code') aria-invalid="true" @enderror>
                    @error('recovery_code')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
            @error('code')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
            <x-ui::button type="submit" block style="margin-top:22px">Continuar</x-ui::button>
        </form>

        <div class="ui-auth-links">
            <button type="button" class="ui-link" x-on:click="toggleInput()">
                <span x-show="! showRecoveryInput">Usar un código de recuperación</span>
                <span x-show="showRecoveryInput">Usar la aplicación de autenticación</span>
            </button>
        </div>
    </div>
</x-layouts::auth>
