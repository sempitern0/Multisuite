@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'loadingLabel' => __('Authenticating...'),
    'separator' => __('Or continue with email'),
])

@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/dashboard');
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <template x-if="supported">
        <x-ui::stack gap="md">
            <x-ui::button variant="secondary" icon="fingerprint" block x-on:click="verify()" x-bind:disabled="loading">
                <span x-show="!loading">{{ $label }}</span><span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
            </x-ui::button>
            <div x-show="error" x-cloak><x-ui::notice tone="bad"><span x-text="error"></span></x-ui::notice></div>
            <x-ui::separator>{{ $separator }}</x-ui::separator>
        </x-ui::stack>
    </template>
</div>
