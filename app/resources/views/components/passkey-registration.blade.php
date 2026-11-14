@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' en ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <x-ui::text tone="muted">Este navegador no admite llaves de acceso.</x-ui::text>
    </template>

    <template x-if="supported && !showForm">
        <div><x-ui::button icon="plus" x-on:click="showForm = true">Añadir llave de acceso</x-ui::button></div>
    </template>

    <template x-if="supported && showForm">
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::field name="passkey_name" label="Nombre de la llave" x-model="name" placeholder="Ej. Portátil, iPhone"
                    help="Un nombre para reconocerla después." x-on:keydown.enter.prevent="register()" x-init="$nextTick(() => $el.focus())" />
                <div x-show="error" x-cloak><x-ui::notice tone="bad"><span x-text="error"></span></x-ui::notice></div>
                <x-ui::toolbar>
                    <x-ui::button x-on:click="register()" x-bind:disabled="loading || !name.trim()">
                        <span x-show="!loading">Registrar llave</span><span x-show="loading" x-cloak>Registrando…</span>
                    </x-ui::button>
                    <x-ui::button variant="ghost" x-on:click="cancel()">Cancelar</x-ui::button>
                </x-ui::toolbar>
            </x-ui::stack>
        </x-ui::panel>
    </template>
</div>
