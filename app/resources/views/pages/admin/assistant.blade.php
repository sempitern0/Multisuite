<?php

use App\Actions\Assistant\SetAssistantMember;
use App\Actions\Assistant\UpdateAssistantSettings;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\AssistantMember;
use App\Models\AssistantSetting;
use App\Models\AssistantToolCall;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Asistente')] class extends Component {
    use NotifiesInterface, ThrottlesActions, WithPagination;

    public bool $enabled = false;

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
        $this->tools = $settings->tools ?? array_keys(AssistantAccess::TOOLS);
        $this->dailyCalls = $settings->daily_calls;
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
            array_values(array_map('strval', $this->tools)), (int) $this->dailyCalls);
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
            Las conversaciones llegarán en la próxima versión; los proveedores de IA y sus claves los configura quien gestiona el servidor, no esta página.
        </x-ui::notice>

        <x-ui::panel>
            <x-ui::form wire:submit="save">
                <x-ui::checkbox name="enabled" wire:model="enabled" :checked="$enabled" label="Asistente activado" />
                <fieldset>
                    <legend class="ui-label">Qué puede consultar</legend>
                    @foreach (\App\Services\Assistant\AssistantAccess::TOOLS as $tool => $label)
                        <x-ui::checkbox name="tools[]" :value="$tool" wire:model="tools" :checked="in_array($tool, $tools, true)" :label="$label" />
                    @endforeach
                    <p class="ui-help">Solo lectura de nombres, tamaños y estados. Nunca el contenido de los archivos.</p>
                </fieldset>
                <x-ui::select name="dailyCalls" label="Límite diario" :options="$this->limits" wire:model="dailyCalls" :value="$dailyCalls"
                    help="Cada consulta del asistente a Multisuite cuenta una vez. Al llegar al límite, el asistente responde que lo intente mañana." />
                <x-ui::button type="submit">Guardar</x-ui::button>
            </x-ui::form>
        </x-ui::panel>

        <x-ui::heading>Quién puede usarlo</x-ui::heading>
        <x-ui::table label="Personas y acceso al asistente" :paginator="$this->people">
            <x-slot:head>
                <tr><th>Persona</th><th>Acceso</th><th>Consultas hoy</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
            </x-slot:head>
            @foreach ($this->people as $person)
                @php
                    $allowed = $person->role === 'owner' || isset($this->members[$person->id]);
                @endphp
                <tr wire:key="assistant-person-{{ $person->id }}">
                    <x-ui::td><x-ui::person :name="$person->name" :sub="$person->roleLabel()" :src="$person->avatarUrl()" :initials="$person->initials()" /></x-ui::td>
                    <x-ui::td label="Acceso">
                        @if ($person->role === 'owner')
                            <x-ui::status>Siempre</x-ui::status>
                        @elseif ($allowed)
                            <x-ui::status>Puede usarlo</x-ui::status>
                        @else
                            <x-ui::status tone="muted">Sin acceso</x-ui::status>
                        @endif
                    </x-ui::td>
                    <x-ui::td label="Consultas hoy">{{ $this->usage[$person->id] ?? 0 }}</x-ui::td>
                    <x-ui::td align="end">
                        @if ($person->role !== 'owner')
                            <x-ui::button size="sm" :variant="$allowed ? 'secondary' : 'primary'" wire:click="allow({{ $person->id }}, {{ $allowed ? 'false' : 'true' }})"
                                :aria-label="($allowed ? 'Quitar el asistente a ' : 'Permitir el asistente a ').$person->name">{{ $allowed ? 'Quitar acceso' : 'Permitir' }}</x-ui::button>
                        @endif
                    </x-ui::td>
                </tr>
            @endforeach
        </x-ui::table>
    </x-ui::stack>
</div>
