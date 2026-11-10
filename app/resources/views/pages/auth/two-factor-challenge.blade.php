<x-layouts::auth :title="__('Verificación en dos pasos')">
    <div class="flex flex-col gap-6">
        <div
            class="relative w-full h-auto"
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
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :title="__('Código de autenticación')"
                    :description="__('Introduce el código de tu aplicación de autenticación.')"
                />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header
                    :title="__('Código de recuperación')"
                    :description="__('Introduce uno de tus códigos de recuperación.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div class="space-y-5 text-center">
                    <div x-show="!showRecoveryInput">
                        <div class="flex items-center justify-center my-5" x-ref="otp">
                            <flux:otp
                                x-model="code"
                                length="6"
                                name="code"
                                label="OTP Code"
                                label:sr-only
                                class="mx-auto"
                             />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <div class="my-5">
                            <flux:input
                                type="text"
                                name="recovery_code"
                                x-ref="recovery_code"
                                x-bind:required="showRecoveryInput"
                                autocomplete="one-time-code"
                                x-model="recovery_code"
                            />
                        </div>

                        @error('recovery_code')
                            <flux:text color="red">
                                {{ $message }}
                            </flux:text>
                        @enderror
                    </div>

                    <flux:button
                        variant="primary"
                        type="submit"
                        class="w-full"
                    >
                        {{ __('Continuar') }}
                    </flux:button>
                </div>

                <div class="mt-5 space-x-0.5 text-sm leading-5 text-center">
                    <button type="button" @click="toggleInput()" class="min-h-12 rounded-md px-2 text-base underline focus-visible:outline-2 focus-visible:outline-offset-4">
                        <span x-show="!showRecoveryInput">Usar un código de recuperación</span>
                        <span x-show="showRecoveryInput">Usar la aplicación de autenticación</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::auth>
