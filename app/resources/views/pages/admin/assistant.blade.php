<?php

use App\Actions\Assistant\RemoveAssistantProvider;
use App\Actions\Assistant\SaveAssistantProvider;
use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Livewire\Concerns\ForgetsSecrets;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\AssistantMember;
use App\Models\AssistantProvider;
use App\Models\AssistantSetting;
use App\Models\AssistantToolCall;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantProviders;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Asistente')] class extends Component {
    use ForgetsSecrets, NotifiesInterface, ThrottlesActions, WithPagination;

    public bool $enabled = false;

    public int $dailyTokens = 200000;

    public bool $contentAllowed = false;

    public bool $semanticEnabled = false;

    public bool $externalEnabled = false;

    /** Provider form: null id = new provider. The key is cleared after every request. */
    public ?int $providerId = null;

    public string $driver = 'anthropic';

    public string $model = '';

    public string $apiKey = '';

    public int $position = 1;

    public bool $providerEnabled = true;

    public bool $editingProvider = false;

    /** @var list<string> */
    public array $tools = [];

    public int $dailyCalls = 200;

    public function boot(): void
    {
        Gate::authorize('manage-assistant');
    }

    public function mount(): void
    {
        $settings = AssistantSetting::current();
        $this->enabled = $settings->enabled;
        // Read tools default to on; change tools stay off until the owner chooses them.
        $this->tools = $settings->tools ?? array_keys(AssistantAccess::TOOLS);
        $this->dailyCalls = $settings->daily_calls;
        $this->dailyTokens = $settings->daily_tokens;
        $this->contentAllowed = $settings->content_allowed;
        $this->semanticEnabled = $settings->semantic_enabled;
        $this->externalEnabled = $settings->external_enabled;
    }

    /** Whether some active provider can prepare search by meaning (OpenAI, Gemini, Mistral, OpenRouter). */
    #[Computed]
    public function semanticProvider(): bool
    {
        return AssistantProvider::where('enabled', true)->whereIn('driver', \App\Services\Assistant\SemanticIndex::EMBEDDING_DRIVERS)->exists();
    }

    /** @return array<int, string> */
    #[Computed]
    public function tokenLimits(): array
    {
        return [50000 => 'Bajo (unas 20 preguntas al día)', 200000 => 'Normal (unas 80 preguntas al día)', 1000000 => 'Alto (unas 400 preguntas al día)', 5000000 => 'Muy alto'];
    }

    #[Computed]
    public function providers(): Collection
    {
        return AssistantProvider::orderBy('position')->orderBy('id')->get();
    }

    public function newProvider(): void
    {
        $this->resetErrorBag();
        $this->providerId = null;
        $this->driver = 'anthropic';
        $this->model = AssistantProviders::DRIVERS['anthropic']['model'];
        $this->apiKey = '';
        $this->position = $this->providers->count() + 1;
        $this->providerEnabled = true;
        $this->editingProvider = true;
    }

    public function editProvider(int $id): void
    {
        $provider = AssistantProvider::findOrFail($id);
        $this->resetErrorBag();
        $this->providerId = $provider->id;
        $this->driver = $provider->driver;
        $this->model = $provider->model;
        $this->apiKey = '';
        $this->position = $provider->position;
        $this->providerEnabled = $provider->enabled;
        $this->editingProvider = true;
    }

    public function updatedDriver(string $driver): void
    {
        if ($this->providerId === null && isset(AssistantProviders::DRIVERS[$driver])) {
            $this->model = AssistantProviders::DRIVERS[$driver]['model'];
        }
    }

    public function saveProvider(): void
    {
        $this->throttle('admin');
        $this->forgetting(['apiKey'], function (): void {
            app(SaveAssistantProvider::class)->handle(Auth::user(), $this->providerId, $this->driver, $this->model,
                $this->apiKey === '' ? null : $this->apiKey, (int) $this->position, $this->providerEnabled);
            $this->editingProvider = false;
            unset($this->providers);
            $this->toast('Proveedor guardado. Usa «Probar» para comprobar la clave.');
        });
    }

    public function cancelProvider(): void
    {
        $this->reset('apiKey', 'providerId', 'editingProvider');
        $this->resetErrorBag();
    }

    public function testProvider(int $id): void
    {
        $this->throttle('admin');
        Gate::authorize('manage-assistant');
        ['result' => $result, 'status' => $status] = app(AssistantProviders::class)->test(AssistantProvider::findOrFail($id));
        $this->toast(AssistantProviders::TEST_RESULTS[$result].($status !== null ? ' (código '.$status.' del proveedor)' : ''), $result === 'ok' ? 'ok' : 'bad');
    }

    public function removeProvider(int $id): void
    {
        $this->throttle('admin');
        app(RemoveAssistantProvider::class)->handle(Auth::user(), $id);
        if ($this->providerId === $id) {
            $this->cancelProvider();
        }
        unset($this->providers);
        $this->toast('Proveedor eliminado junto con su clave.');
    }

    /** @return array<int, string> */
    #[Computed]
    public function limits(): array
    {
        $options = [];
        foreach ([50, 100, 200, 500, 1000] as $calls) {
            $options[$calls] = $calls.' consultas al día por persona';
        }

        return $options;
    }

    public function save(): void
    {
        $this->throttle('admin');
        app(UpdateAssistantSettings::class)->handle(Auth::user(), $this->enabled,
            array_values(array_map('strval', $this->tools)), (int) $this->dailyCalls, (int) $this->dailyTokens, $this->contentAllowed, $this->semanticEnabled, $this->externalEnabled);
        $this->toast($this->enabled ? 'Asistente activado y guardado.' : 'Ajustes guardados. El asistente está desactivado.');
    }

    public function allow(int $userId, bool $allowed): void
    {
        $this->throttle('admin');
        app(SetAssistantMember::class)->handle(Auth::user(), User::findOrFail($userId), $allowed);
        unset($this->people);
        $this->toast($allowed ? 'Ahora puede usar el asistente.' : 'Ya no puede usar el asistente.');
    }

    #[Computed]
    public function people(): LengthAwarePaginator
    {
        return User::query()->orderBy('name')->orderBy('id')->paginate(Auth::user()->preferences()->perPage());
    }

    /** @return array<int, true> */
    #[Computed]
    public function members(): array
    {
        return AssistantMember::whereIn('user_id', $this->people->pluck('id'))->pluck('user_id')->flip()->map(fn () => true)->all();
    }

    /** @return array<int, int> */
    #[Computed]
    public function usage(): array
    {
        return AssistantToolCall::whereIn('user_id', $this->people->pluck('id'))
            ->whereIn('outcome', AssistantToolCall::CONSUMED)->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('user_id, count(*) as calls')->groupBy('user_id')->pluck('calls', 'user_id')->map(fn ($calls) => (int) $calls)->all();
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Asistente" description="Decide si el asistente está disponible, quién puede usarlo y qué puede consultar." />

    <x-ui::stack gap="md">
        <x-ui::notice tone="info">
            El asistente actúa siempre como la persona que lo usa: solo ve sus propios archivos y servicios, también en tu caso.
            Las preguntas y los nombres de archivos que consulte se envían al proveedor de IA que elijas; el contenido de los archivos, solo si lo permites abajo y cada persona lo acepta.
            @can('use-assistant')<x-ui::link :href="route('assistant.index')">Abrir el asistente</x-ui::link>@endcan
        </x-ui::notice>

        <x-ui::panel>
            <x-ui::form wire:submit="save">
                <x-ui::checkbox switch name="enabled" wire:model="enabled" :checked="$enabled" label="Asistente activado" description="Con el asistente apagado nadie puede usarlo, ni aquí ni desde aplicaciones externas." />
                <fieldset class="ui-check-group">
                    <legend class="ui-label">Qué puede consultar</legend>
                    @foreach (\App\Services\Assistant\AssistantAccess::TOOLS as $tool => $label)
                        <x-ui::checkbox name="tools[]" :value="$tool" wire:model="tools" :checked="in_array($tool, $tools, true)" :label="$label" />
                    @endforeach
                    <p class="ui-help">Solo lectura de nombres, tamaños y estados.</p>
                </fieldset>
                <fieldset class="ui-check-group">
                    <legend class="ui-label">Qué puede cambiar en Mis archivos</legend>
                    @foreach (\App\Services\Assistant\AssistantAccess::WRITE_TOOLS as $tool => $label)
                        <x-ui::checkbox name="tools[]" :value="$tool" wire:model="tools" :checked="in_array($tool, $tools, true)" :label="$label" />
                    @endforeach
                    <p class="ui-help">Cada cambio se le enseña a la persona y solo se hace si lo aprueba. Nunca borra nada definitivamente.</p>
                </fieldset>
                <x-ui::checkbox switch name="contentAllowed" wire:model="contentAllowed" :checked="$contentAllowed" label="Permitir que el asistente lea el texto de los archivos de quien lo acepte"
                    description="Texto, Markdown, CSV, JSON y PDF, por partes. Cada persona decide en su página del asistente si lo permite para sus archivos; el texto leído se envía al proveedor de IA." />
                <x-ui::checkbox switch name="semanticEnabled" wire:model="semanticEnabled" :checked="$semanticEnabled" label="Búsqueda por significado en los archivos de quien acepte la lectura"
                    :description="'Cada 15 minutos se prepara el texto de los archivos de esas personas para que el asistente encuentre cosas por lo que tratan. El texto se envía al proveedor para prepararlo y se guarda cifrado.'.($this->semanticProvider ? '' : ' Necesita un proveedor activo de OpenAI, Gemini, Mistral u OpenRouter (Anthropic no ofrece este servicio).')" />
                <x-ui::checkbox switch name="externalEnabled" wire:model="externalEnabled" :checked="$externalEnabled" label="Permitir conectar aplicaciones externas (Claude, ChatGPT…) por MCP"
                    :description="'Cada persona con acceso al asistente podrá conectar una aplicación desde ella con su cuenta de Multisuite. Solo consultas, nunca cambios. Actívalo solo si Multisuite es accesible por la red privada (VPN y HTTPS). Dirección: '.url('/mcp')" />
                <x-ui::select name="dailyCalls" label="Consultas a Multisuite" :options="$this->limits" wire:model="dailyCalls" :value="$dailyCalls"
                    help="Cada vez que el asistente mira tus archivos, tu espacio o tus servicios cuenta una consulta." />
                <x-ui::select name="dailyTokens" label="Uso del proveedor de IA por persona y día" :options="$this->tokenLimits" wire:model="dailyTokens" :value="$dailyTokens"
                    help="Limita lo que cada persona puede gastar en el proveedor. Al llegar al límite, el asistente pide volver mañana." />
                <x-ui::button type="submit">Guardar</x-ui::button>
            </x-ui::form>
        </x-ui::panel>

        <x-ui::heading>Proveedores de IA</x-ui::heading>
        <x-ui::panel>
            <x-ui::stack gap="md">
                <p class="ui-help">Se usan en este orden: si el primero falla, responde el siguiente. La clave se guarda cifrada y no se vuelve a mostrar.</p>
                @forelse ($this->providers as $provider)
                    <x-ui::row wire:key="provider-{{ $provider->id }}">
                        <div>
                            <strong>{{ $provider->position }}. {{ \App\Services\Assistant\AssistantProviders::DRIVERS[$provider->driver]['label'] ?? $provider->driver }}</strong>
                            <span class="ui-help">· {{ $provider->model }} · clave …{{ $provider->key_hint }}</span>
                            @if (! $provider->enabled)<x-ui::status tone="muted">Pausado</x-ui::status>@endif
                        </div>
                        <x-ui::actions>
                            <x-ui::button size="sm" variant="secondary" wire:click="testProvider({{ $provider->id }})" wire:loading.attr="disabled" wire:target="testProvider({{ $provider->id }})">Probar</x-ui::button>
                            <x-ui::button size="sm" variant="ghost" wire:click="editProvider({{ $provider->id }})">Cambiar</x-ui::button>
                            <x-ui::confirm title="¿Eliminar este proveedor?">
                                <x-slot:trigger><x-ui::button size="sm" variant="ghost" icon="trash" label="Eliminar proveedor" /></x-slot:trigger>
                                Se borra también su clave. El asistente usará los demás proveedores activos.
                                <x-slot:action><x-ui::button variant="danger" wire:click="removeProvider({{ $provider->id }})">Eliminar</x-ui::button></x-slot:action>
                            </x-ui::confirm>
                        </x-ui::actions>
                    </x-ui::row>
                @empty
                    <x-ui::notice tone="warn">Todavía no hay proveedores: el asistente no puede responder hasta que añadas uno.</x-ui::notice>
                @endforelse

                @if ($editingProvider)
                    <x-ui::form wire:submit="saveProvider">
                        <x-ui::select name="driver" label="Proveedor" :options="array_map(fn ($item) => $item['label'], \App\Services\Assistant\AssistantProviders::DRIVERS)" wire:model.live="driver" :value="$driver" />
                        <x-ui::field name="model" label="Modelo" wire:model="model" maxlength="100" autocomplete="off"
                            help="El nombre exacto que da el proveedor. Hemos puesto uno recomendado; cámbialo si prefieres otro." />
                        <x-ui::password name="apiKey" :label="$providerId === null ? 'Clave de la API' : 'Clave nueva (déjala vacía para conservar la actual)'" wire:model="apiKey" autocomplete="off"
                            help="Se crea en la web del proveedor. Se guarda cifrada y no se vuelve a mostrar." />
                        <x-ui::field name="position" type="number" label="Orden" wire:model="position" min="1" max="99" />
                        <x-ui::checkbox switch name="providerEnabled" wire:model="providerEnabled" :checked="$providerEnabled" label="Activo" description="Un proveedor inactivo se conserva pero no se usa." />
                        <x-ui::actions>
                            <x-ui::button type="submit">Guardar proveedor</x-ui::button>
                            <x-ui::button variant="ghost" wire:click="cancelProvider">Cancelar</x-ui::button>
                        </x-ui::actions>
                    </x-ui::form>
                @else
                    <x-ui::button icon="plus" variant="secondary" wire:click="newProvider">Añadir proveedor</x-ui::button>
                @endif
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::heading>Quién puede usarlo</x-ui::heading>
        <x-ui::table label="Personas y acceso al asistente" :paginator="$this->people">
            <x-slot:head>
                <tr><th>Persona</th><th>Acceso</th><th>Consultas hoy</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
            </x-slot:head>
            @foreach ($this->people as $person)
                @php
                    $allowed = $person->isOwner() || isset($this->members[$person->id]);
                @endphp
                <tr wire:key="assistant-person-{{ $person->id }}">
                    <x-ui::td><x-ui::person :name="$person->name" :sub="$person->roleLabel()" :src="$person->avatarUrl()" :initials="$person->initials()" /></x-ui::td>
                    <x-ui::td label="Acceso">
                        @if ($person->isOwner())
                            <x-ui::status>Siempre</x-ui::status>
                        @elseif ($allowed)
                            <x-ui::status>Puede usarlo</x-ui::status>
                        @else
                            <x-ui::status tone="muted">Sin acceso</x-ui::status>
                        @endif
                    </x-ui::td>
                    <x-ui::td label="Consultas hoy">{{ $this->usage[$person->id] ?? 0 }}</x-ui::td>
                    <x-ui::td align="end">
                        @if (! $person->isOwner())
                            <x-ui::button size="sm" :variant="$allowed ? 'secondary' : 'primary'" wire:click="allow({{ $person->id }}, {{ $allowed ? 'false' : 'true' }})"
                                :aria-label="($allowed ? 'Quitar el asistente a ' : 'Permitir el asistente a ').$person->name">{{ $allowed ? 'Quitar acceso' : 'Permitir' }}</x-ui::button>
                        @endif
                    </x-ui::td>
                </tr>
            @endforeach
        </x-ui::table>
    </x-ui::stack>
</div>
