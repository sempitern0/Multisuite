<x-layouts::auth :title="__('Verificar correo')">
    <div class="mt-4 flex flex-col gap-6">
        <flux:text class="text-center">
            {{ __('Abre el enlace que te hemos enviado por correo.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
                {{ __('Te hemos enviado un nuevo enlace.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Reenviar enlace') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                    {{ __('Cerrar sesión') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
