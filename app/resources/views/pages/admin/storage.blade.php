<?php

use App\Actions\Storage\UpdateStorageLimits;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\User;
use App\Services\Storage\StorageOverview;
use App\Support\Bytes;
use App\Support\SizeInput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Almacenamiento')] class extends Component {
    use NotifiesInterface, ThrottlesActions, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('view-storage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function overview(): array
    {
        return app(StorageOverview::class)->build(Auth::user(), Str::limit(trim($this->search), 100, ''), Auth::user()->preferences()->perPage());
    }

    /** @param array<string, mixed> $size preset, amount, unit from the size picker */
    public function saveUser(int $userId, array $size, UpdateStorageLimits $limits): void
    {
        $this->throttle('admin-storage');
        $target = User::findOrFail($userId);
        Gate::authorize('manage-storage-quota', $target);
        $bytes = $this->bytes($size, 'quota_'.$userId);
        $limits->userQuota(Auth::user(), $target, $bytes, 'quota_'.$userId);
        $this->saved('Espacio de '.$target->name.': '.Bytes::format($bytes).' como máximo');
    }

    /** @param array<string, mixed> $size */
    public function savePool(string $pool, array $size, UpdateStorageLimits $limits): void
    {
        $this->throttle('admin-storage');
        abort_unless(preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $pool) === 1, 404);
        Gate::authorize('manage-storage-pools');
        $bytes = $this->bytes($size, 'pool_'.$pool);
        $limits->poolLimit(Auth::user(), $pool, $bytes, 'pool_'.$pool);
        $this->saved('Límite del disco '.$pool.': '.Bytes::format($bytes));
    }

    /** @param array<string, mixed> $size */
    public function saveDefault(array $size, UpdateStorageLimits $limits): void
    {
        $this->throttle('admin-storage');
        Gate::authorize('manage-storage-pools');
        $bytes = $this->bytes($size, 'default');
        $limits->defaultQuota(Auth::user(), $bytes, 'default');
        $this->saved('Espacio por defecto: '.Bytes::format($bytes));
    }

    /** Input errors (preset, amount, unit) are reported next to the form that sent them. */
    private function bytes(array $size, string $field): int
    {
        $this->resetErrorBag();
        try {
            return SizeInput::bytes($size, $field);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => $exception->validator->errors()->first()]);
        }
    }

    private function saved(string $message): void
    {
        unset($this->overview);
        $this->toast($message);
    }
}; ?>

<div>
    @php
        $o = $this->overview;
        $fmt = fn (?int $bytes): string => \App\Support\Bytes::format($bytes);
    @endphp
    <x-ui::page-header eyebrow="Administración" title="Almacenamiento" description="Decide cuánto espacio puede ocupar cada persona. No hace falta tocar discos ni particiones: el servidor ya los tiene preparados y aquí solo repartes el espacio." />

    <x-ui::stack gap="lg">
        <x-ui::panel aria-labelledby="how-it-works">
            <x-ui::stack gap="sm">
                <x-ui::heading id="how-it-works">Cómo funciona</x-ui::heading>
                <x-ui::list>
                    <li><strong>Discos:</strong> el límite de cada disco es lo máximo que el portal guardará en él. Nunca se escribe más allá del espacio libre real menos un margen de seguridad.</li>
                    <li><strong>Personas:</strong> cada una tiene un máximo personal. Es un techo, no un espacio apartado: lo que no usa sigue libre para los demás.</li>
                    <li><strong>Seguridad:</strong> si un disco se llena, se desconecta o hay dudas sobre su espacio, el portal deja de aceptar contenido nuevo en él. Nunca borra archivos para hacer sitio.</li>
                </x-ui::list>
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::stack gap="md" aria-labelledby="disks">
            <x-ui::heading id="disks">Discos</x-ui::heading>
            @if ($o['inventoryError'])
                <x-ui::notice tone="bad">La configuración de discos del servidor tiene un error: {{ $o['inventoryError'] }} Pide al responsable técnico que revise <code>NAS_STORAGE_VOLUMES</code> (ver docs/STORAGE.md).</x-ui::notice>
            @elseif (! $o['configured'] && $o['disks'] === [])
                <x-ui::empty-state icon="database" title="Todavía no hay discos preparados">Por seguridad, el portal no puede elegir carpetas ni discos por sí mismo. El responsable técnico debe conectarlos una vez en el servidor siguiendo docs/STORAGE.md. Después aparecerán aquí.</x-ui::empty-state>
            @endif
            @if ($o['virtualHost'] && $o['disks'] !== [])
                <x-ui::notice tone="warn"><strong>Capacidad del disco virtual de Docker Desktop.</strong> La capacidad que ves es la del disco virtual de Docker (suele llegar a 1 TB), no la de tu disco físico. Los archivos sí ocupan espacio real en el ordenador: fija un límite menor que el espacio libre de ese disco. En un servidor Linux con los discos montados directamente, estas cifras son exactas.</x-ui::notice>
            @endif

            @foreach ($o['disks'] as $disk)
                <x-ui::panel wire:key="disk-{{ $disk['id'] }}" aria-labelledby="disk-{{ $disk['id'] }}">
                    <x-ui::stack gap="md">
                        <x-ui::row>
                            <x-ui::heading :level="3" id="disk-{{ $disk['id'] }}">{{ $disk['id'] }}</x-ui::heading>
                            <x-ui::tag :tone="$disk['status'] === 'observed' ? null : 'red'">{{ $disk['statusLabel'] }}</x-ui::tag>
                        </x-ui::row>
                        @if ($disk['locations'] !== [])
                            <x-ui::text tone="muted">Carpetas en este disco: {{ implode(', ', $disk['locations']) }}.</x-ui::text>
                        @endif
                        @if ($disk['segments'] !== [])
                            <x-ui::segment-bar
                                :label="'Disco de '.$fmt($disk['total']).': '.collect($disk['segments'])->map(fn ($segment) => $segment['label'].' '.$fmt($segment['bytes']))->implode(', ')"
                                :segments="array_map(fn ($segment) => $segment + ['text' => $fmt($segment['bytes'])], $disk['segments'])" />
                        @endif
                        <x-ui::stats :items="[
                            'Límite del portal' => $disk['limit'] === null ? 'Sin configurar (no admite contenido)' : $fmt($disk['limit']),
                            'Usado por personas' => $fmt($disk['used']).($disk['reserved'] > 0 ? ' + '.$fmt($disk['reserved']).' en curso' : ''),
                            'Máximo recomendado' => $disk['suggested'] === null ? 'Desconocido' : $fmt($disk['suggested']),
                        ]" />

                        @if ($disk['staleCount'] > 0)
                            <x-ui::notice tone="info">
                                {{ $disk['staleCount'] }} {{ $disk['staleCount'] === 1 ? 'subida interrumpida sigue' : 'subidas interrumpidas siguen' }} ocupando {{ $fmt($disk['staleAutomatic'] + $disk['staleManual']) }} del espacio de sus propietarios.
                                @if ($disk['staleAutomatic'] > 0) {{ $fmt($disk['staleAutomatic']) }} se liberarán solos: la limpieza automática comprueba cada 10 minutos que no quedan restos en el disco. @endif
                                @if ($disk['staleManual'] > 0) {{ $fmt($disk['staleManual']) }} no tienen limpieza automática: pide al responsable técnico que los revise (docs/STORAGE.md, «Reconciliación»). @endif
                            </x-ui::notice>
                        @endif
                        @if ($disk['limitAboveCapacity'])
                            <x-ui::notice tone="warn">El límite es mayor que el espacio realmente disponible. No es peligroso (el portal comprueba el espacio real antes de aceptar contenido), pero las personas podrían quedarse sin sitio antes de llegar a su máximo.</x-ui::notice>
                        @endif

                        @if ($o['canManagePools'] && $disk['editable'])
                            <x-ui::size-picker id="pool-{{ $disk['id'] }}" :presets="$o['presets']" :units="$o['units']" :selected="$disk['limit']" label="Límite del portal en este disco"
                                :extra="$disk['suggested'] !== null && $disk['suggested'] > 0 ? [$disk['suggested'] => 'Todo lo recomendado ('.$fmt($disk['suggested']).')', 0 => 'No usar este disco'] : [0 => 'No usar este disco']"
                                :submit-label="'Guardar límite del disco '.$disk['id']" :error="$errors->first('pool_'.$disk['id'])"
                                x-on:size-chosen="$wire.savePool('{{ $disk['id'] }}', $event.detail)" />
                        @elseif (! $o['canManagePools'])
                            <x-ui::text tone="muted">Solo el propietario puede cambiar el límite de los discos.</x-ui::text>
                        @endif
                    </x-ui::stack>
                </x-ui::panel>
            @endforeach
        </x-ui::stack>

        <x-ui::stack gap="md" aria-labelledby="hardware">
            <x-ui::heading id="hardware">Discos del servidor</x-ui::heading>
            @if ($o['hardware'] === null)
                <x-ui::text>Para ver aquí todos los discos y particiones del servidor, ejecuta <code>make nas-discover</code> en él. Para usarlos con el portal: <code>make nas-apply TARGETS="/punto/de/montaje"</code> y después <code>make up</code>. Nunca se formatea ni se particiona nada.</x-ui::text>
            @else
                <x-ui::text>Última detección{{ $o['hardware']['host'] !== '' ? ' en '.$o['hardware']['host'] : '' }}: {{ $o['hardware']['generated_at'] }}. Solo lectura; para cambiarlos usa <code>make nas-apply</code> en el servidor.</x-ui::text>
                @forelse ($o['hardware']['disks'] as $device)
                    <x-ui::table :label="'Particiones de '.$device['name']" wire:key="device-{{ $device['name'] }}">
                        <x-slot:head>
                            <tr><th>{{ $device['model'] !== '' ? $device['model'] : 'Disco' }} · {{ $device['name'] }} · {{ $fmt($device['size']) }}{{ $device['removable'] ? ' · extraíble' : '' }}</th><th>Formato</th><th>Tamaño</th><th>Montaje</th><th>Portal</th></tr>
                        </x-slot:head>
                        @forelse ($device['partitions'] as $part)
                            <tr>
                                <x-ui::td primary>{{ $part['name'] }}</x-ui::td>
                                <x-ui::td label="Formato">{{ $part['fstype'] !== '' ? $part['fstype'] : 'sin formato' }}</x-ui::td>
                                <x-ui::td label="Tamaño">{{ $fmt($part['size']) }}</x-ui::td>
                                <x-ui::td label="Montaje">{{ $part['mountpoint'] === '' ? 'No montada' : $part['mountpoint'].($part['fs_available'] !== null ? ' · libre '.$fmt($part['fs_available']) : '') }}</x-ui::td>
                                <x-ui::td label="Portal">@if ($part['in_use'])<x-ui::status>En uso por el portal</x-ui::status>@else — @endif</x-ui::td>
                            </tr>
                        @empty
                            <x-ui::table-empty colspan="5">Sin particiones con sistema de archivos (disco sin usar).</x-ui::table-empty>
                        @endforelse
                    </x-ui::table>
                @empty
                    <x-ui::text>La última detección no encontró discos.</x-ui::text>
                @endforelse
            @endif
        </x-ui::stack>

        <x-ui::stack gap="sm" aria-labelledby="backup">
            <x-ui::heading id="backup">Copia de seguridad</x-ui::heading>
            @php($backupAt = $o['backup']['at'] ?? null)
            @switch($o['backup']['state'] ?? null)
                @case('ok') <x-ui::notice>Última copia terminada y comprobada el {{ $backupAt->format('d/m/Y H:i') }}.</x-ui::notice> @break
                @case('running') <x-ui::notice tone="info">Hay una copia en curso{{ $backupAt ? ' desde el '.$backupAt->format('d/m/Y H:i') : '' }}.</x-ui::notice> @break
                @case('stale') <x-ui::notice tone="warn">La última copia es del {{ $backupAt->format('d/m/Y H:i') }}. Revisa en el servidor que la copia programada sigue funcionando.</x-ui::notice> @break
                @case('failed') <x-ui::notice tone="bad">La última copia falló{{ $backupAt ? ' el '.$backupAt->format('d/m/Y H:i') : '' }}. Los archivos nuevos no están protegidos hasta que se repita en el servidor con <code>make nas-backup</code>.</x-ui::notice> @break
                @default <x-ui::text>Todavía no se ha hecho ninguna copia a un disco externo o a otro lugar. Se configura en el servidor (<code>backup.env</code> y <code>make nas-backup</code>); el portal solo muestra el resultado.</x-ui::text>
            @endswitch
        </x-ui::stack>

        <x-ui::panel aria-labelledby="sharing">
            <x-ui::stack gap="md">
                <x-ui::heading id="sharing">Reparto</x-ui::heading>
                <x-ui::stats :items="[
                    'Límite total de discos' => $fmt($o['poolLimitTotal']),
                    'Prometido a '.$o['userCount'].' '.($o['userCount'] === 1 ? 'persona' : 'personas') => $fmt($o['assigned']),
                    'Ocupado en total' => $fmt($o['usedTotal']),
                ]" />
                @if ($o['equalShare'] !== null)
                    <x-ui::text>Si repartes por igual, a cada persona le corresponden unos {{ $fmt($o['equalShare']) }}.</x-ui::text>
                @endif
                @if ($o['overcommitted'])
                    <x-ui::notice tone="warn">Has prometido más espacio del que existe. Es habitual (no todo el mundo llena su espacio) y no pone datos en riesgo: cuando el disco se llene, nadie podrá añadir más hasta liberar espacio o ampliar el disco.</x-ui::notice>
                @endif
                <x-ui::heading :level="3">Espacio por defecto para personas nuevas: {{ $fmt($o['default']) }}</x-ui::heading>
                <x-ui::text tone="muted">Se aplica a quien todavía no tiene un espacio propio, la primera vez que añade contenido. No cambia a quien ya tiene uno.</x-ui::text>
                @if ($o['canManagePools'])
                    <x-ui::size-picker id="default" :presets="$o['presets']" :units="$o['units']" :selected="$o['default']" label="Espacio por defecto"
                        submit-label="Guardar espacio por defecto" :error="$errors->first('default')" x-on:size-chosen="$wire.saveDefault($event.detail)" />
                @endif
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::stack gap="md" aria-labelledby="people">
            <x-ui::heading id="people">Personas</x-ui::heading>
            <x-ui::toolbar>
                <x-ui::field name="q" type="search" label="Buscar por nombre, usuario o correo" maxlength="100" wire:model.live.debounce.300ms="search" />
            </x-ui::toolbar>
            <x-ui::rows aria-label="Espacio por persona">
                @forelse ($o['rows'] as $row)
                    <x-ui::stack gap="sm" wire:key="quota-{{ $row['user']->id }}" id="user-{{ $row['user']->id }}">
                        <x-ui::row>
                            <strong>{{ $row['user']->name }}</strong>
                            <x-ui::tag :tone="$row['user']->role === 'member' ? 'grey' : null">{{ $row['user']->roleLabel() }}</x-ui::tag>
                        </x-ui::row>
                        <x-ui::meter :value="$row['percent']" :label="'Espacio usado por '.$row['user']->name">
                            {{ $fmt($row['used']) }} usados de {{ $fmt($row['limit']) }}@if ($row['reserved'] > 0) ({{ $fmt($row['reserved']) }} subiendo)@endif
                            @if ($row['followsDefault']) · usa el espacio por defecto @endif
                            @if ($row['limit'] === 0) · no puede añadir contenido @endif
                        </x-ui::meter>
                        @if ($row['canManage'])
                            <x-ui::size-picker id="quota-{{ $row['user']->id }}" :presets="$o['presets']" :units="$o['units']" :selected="$row['followsDefault'] ? null : $row['limit']"
                                :submit-label="'Guardar espacio de '.$row['user']->name" :error="$errors->first('quota_'.$row['user']->id)"
                                x-on:size-chosen="$wire.saveUser({{ $row['user']->id }}, $event.detail)" />
                        @endif
                    </x-ui::stack>
                @empty
                    <x-ui::text>No hay personas que coincidan con la búsqueda.</x-ui::text>
                @endforelse
            </x-ui::rows>
            @if ($o['users']->hasPages())
                <x-ui::pagination :paginator="$o['users']" />
            @endif
        </x-ui::stack>
    </x-ui::stack>
</div>
