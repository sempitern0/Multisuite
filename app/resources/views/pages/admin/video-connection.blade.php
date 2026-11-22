<?php

use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\JellyfinAccounts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Vídeo (Jellyfin, docs/JELLYFIN.md): the owner connects it once and chooses the hardware
 * acceleration among what this host can offer (make video-detect). Applied through Jellyfin's API.
 */
new #[Title('Conexión de Vídeo')] class extends Component {
    use ForgetsSecrets, NotifiesInterface, ThrottlesActions;

    public string $name = 'multisuite-admin';

    public string $password = '';

    public string $acceleration = 'none';

    public function boot(): void
    {
        Gate::authorize('manage-storage-pools');
        abort_unless(config('portal.services.video.enabled') && config('provisioning.video.enabled'), 404);
    }

    public function mount(): void
    {
        $this->acceleration = $this->setupState === 'connected' ? app(JellyfinAccounts::class)->acceleration() : (string) array_key_first(JellyfinAccounts::accelerationChoices());
    }

    #[Computed]
    public function setupState(): string
    {
        return app(JellyfinAccounts::class)->setupState();
    }

    public function connect(): void
    {
        $this->throttle('admin');
        $this->forgetting(['password'], function (): void {
            $user = Auth::user();
            $this->validate([
                // A separate administrator: the owner's own Vídeo account is created like everyone else's.
                'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::notIn(array_filter([$user->username, $user->email]))],
                'password' => ['required', 'string', 'min:12', 'max:1024'],
            ], [
                'name.not_in' => 'Usa un nombre distinto de tu usuario de Multisuite: tu propia cuenta de Vídeo se crea aparte.',
                'name.regex' => 'Solo letras sin tildes, números, punto, guion, guion bajo o @.',
            ]);
            try {
                app(JellyfinAccounts::class)->connect($user, $this->name, $this->password);
            } catch (LockTimeoutException) {
                $this->addError('password', 'La conexión ya se está preparando. Espera unos segundos y vuelve a intentarlo.');

                return;
            } catch (RuntimeException $exception) {
                $this->addError('password', match ($exception->getMessage()) {
                    'credentials' => 'Jellyfin ya está configurado y no acepta este nombre y contraseña. Usa los de su cuenta administradora.',
                    'not_admin' => 'Esa cuenta de Jellyfin no es administradora. Necesitamos una administradora para preparar las demás.',
                    'wizard' => 'Jellyfin no ha aceptado la configuración inicial. Actualiza el estado: puede haberse configurado mientras conectabas.',
                    'rate_limited' => 'Jellyfin ha limitado los intentos. Espera unos minutos antes de volver a probar.',
                    default => 'Multisuite no puede comunicarse correctamente con Jellyfin. Comprueba que Vídeo haya terminado de arrancar y actualiza el estado.',
                });
                unset($this->setupState);

                return;
            }
            unset($this->setupState);
            $this->acceleration = app(JellyfinAccounts::class)->acceleration();
            $this->toast('Vídeo conectado. Cada persona tendrá su cuenta al volver a entrar en Multisuite.');
        });
    }

    public function saveAcceleration(): void
    {
        $this->throttle('admin');
        $this->validate(['acceleration' => ['required', Rule::in(array_keys(JellyfinAccounts::accelerationChoices()))]]);
        try {
            app(JellyfinAccounts::class)->applyAcceleration($this->acceleration);
        } catch (RuntimeException) {
            $this->addError('acceleration', 'Jellyfin no ha guardado el cambio. Comprueba que Vídeo esté en marcha y vuelve a intentarlo.');

            return;
        }
        $this->toast('Aceleración guardada en Jellyfin');
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Conexión de Vídeo" description="Multisuite prepara una cuenta de Jellyfin para cada persona con su biblioteca privada, la carpeta común y su límite de edad." />
    <x-ui::container>
        <x-ui::stack gap="lg">
            <x-ui::panel>
                @if ($this->setupState === 'unavailable')
                    <x-ui::stack gap="md">
                        <p role="status">Multisuite no puede consultar Jellyfin en este momento. Espera a que Vídeo termine de arrancar (<code>make video-up</code>).</p>
                        <x-ui::button wire:click="$refresh" variant="secondary">Actualizar estado</x-ui::button>
                    </x-ui::stack>
                @else
                    <x-ui::stack gap="md">
                        @if ($this->setupState === 'empty')
                            <p>Jellyfin está recién instalado. Elige el nombre y la contraseña de su administrador: Multisuite completará la configuración inicial en español y sin acceso remoto.</p>
                        @elseif ($this->setupState === 'connected')
                            <p>Vídeo está conectado. Vuelve a autorizarlo solo si cambiaste la cuenta administradora de Jellyfin.</p>
                        @else
                            <p>Jellyfin ya está configurado. Escribe el nombre y la contraseña de una cuenta administradora suya; no se creará otra.</p>
                        @endif
                        <x-ui::form wire:submit="connect">
                            <x-ui::field name="name" label="Administrador de Jellyfin" wire:model="name" required autocomplete="username" help="Es solo para administrar Jellyfin; tu cuenta personal de Vídeo se crea aparte." />
                            <x-ui::password name="password" label="Contraseña del administrador" wire:model="password" required autocomplete="{{ $this->setupState === 'empty' ? 'new-password' : 'current-password' }}" help="{{ $this->setupState === 'empty' ? 'Al menos 12 caracteres. Guárdala en tu gestor de contraseñas.' : '' }}" />
                            <x-ui::button type="submit">{{ $this->setupState === 'connected' ? 'Volver a autorizar' : 'Conectar Vídeo' }}</x-ui::button>
                        </x-ui::form>
                    </x-ui::stack>
                @endif
            </x-ui::panel>

            @if ($this->setupState === 'connected')
                <x-ui::panel>
                    <x-ui::stack gap="md">
                        <x-ui::heading>Aceleración por hardware</x-ui::heading>
                        <p>Convierte los vídeos más rápido y con menos consumo cuando un dispositivo no puede reproducir el original. Se ofrecen solo las opciones que este equipo tiene{{ JellyfinAccounts::vendor() === 'none' ? '; no se detectó ninguna tarjeta gráfica, así que se usa el procesador' : '' }}.</p>
                        <x-ui::form wire:submit="saveAcceleration">
                            <x-ui::select name="acceleration" label="Usar" :options="JellyfinAccounts::accelerationChoices()" wire:model="acceleration" help="Si cambias la tarjeta gráfica, ejecuta make video-detect y reinicia Vídeo." />
                            <x-ui::button type="submit" variant="secondary">Guardar</x-ui::button>
                        </x-ui::form>
                    </x-ui::stack>
                </x-ui::panel>
            @endif
        </x-ui::stack>
    </x-ui::container>
</div>
