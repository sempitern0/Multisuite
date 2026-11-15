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
        $backup = $o['backup'];
        $backupAt = $backup['at'] ?? null;
        $backupText = match ($backup['state'] ?? null) {
            'ok' => 'Correcta · '.$backupAt->format('d/m H:i'),
            'running' => 'En curso',
            'stale' => 'Antigua · '.$backupAt->format('d/m H:i'),
            'failed' => 'Falló',
            default => 'Sin copia externa',
        };
    @endphp
    <x-ui::page-header eyebrow="Administración" title="Almacenamiento" description="Discos del servidor y espacio de cada persona." />

    <x-ui::stack gap="lg">
        @if ($o['inventoryError'])
            <x-ui::notice tone="bad">Error en la configuración de discos: {{ $o['inventoryError'] }} Revisa <code>NAS_STORAGE_VOLUMES</code> (docs/STORAGE.md).</x-ui::notice>
        @endif
        @if ($o['virtualHost'] && $o['disks'] !== [])
            <x-ui::notice tone="warn">Docker Desktop: la capacidad mostrada es la del disco virtual, no la de tu disco físico. Fija límites por debajo de su espacio libre.</x-ui::notice>
        @endif
        @if (in_array($backup['state'] ?? null, ['failed', 'stale'], true))
            <x-ui::notice tone="{{ $backup['state'] === 'failed' ? 'bad' : 'warn' }}">{{ $backup['state'] === 'failed' ? 'La última copia falló'.($backupAt ? ' el '.$backupAt->format('d/m/Y H:i') : '').'.' : 'La última copia es del '.$backupAt->format('d/m/Y H:i').'.' }} Revisa <code>make nas-backup</code> en el servidor.</x-ui::notice>
        @endif

        <x-ui::panel aria-label="Resumen">
            <x-ui::stats :items="[
                'Límite de los discos' => $fmt($o['poolLimitTotal']),
                'Ocupado' => $fmt($o['usedTotal']),
                'Prometido a '.$o['userCount'].' '.($o['userCount'] === 1 ? 'persona' : 'personas') => $fmt($o['assigned']).($o['overcommitted'] ? ' (más de lo que hay)' : ''),
                'Copia de seguridad' => $backupText,
            ]" />
        </x-ui::panel>

        <x-ui::stack gap="sm" aria-labelledby="disks">
            <x-ui::heading id="disks">Discos del portal</x-ui::heading>
            <x-ui::table label="Discos del portal">
                <x-slot:head><tr><th>Disco</th><th>Ocupación</th><th>Límite del portal</th>@if ($o['canManagePools'])<th><x-ui::sr>Cambiar límite</x-ui::sr></th>@endif</tr></x-slot:head>
                @forelse ($o['disks'] as $disk)
                    <tr wire:key="disk-{{ $disk['id'] }}">
                        <x-ui::td primary :sub="$disk['status'] !== 'observed' ? $disk['statusLabel'] : (array_diff($disk['locations'], [$disk['id']]) === [] ? null : implode(', ', $disk['locations']))">{{ $disk['id'] }}</x-ui::td>
                        <x-ui::td label="Ocupación">
                            @if ($disk['segments'] !== [])
                                <x-ui::segment-bar
                                    :label="'Disco de '.$fmt($disk['total']).': '.collect($disk['segments'])->map(fn ($segment) => $segment['label'].' '.$fmt($segment['bytes']))->implode(', ')"
                                    :segments="array_map(fn ($segment) => $segment + ['text' => $fmt($segment['bytes'])], $disk['segments'])" />
                            @else
                                —
                            @endif
                        </x-ui::td>
                        <x-ui::td label="Límite" nowrap :sub="'Usado '.$fmt($disk['used']).($disk['reserved'] > 0 ? ' + '.$fmt($disk['reserved']).' subiendo' : '').' · Máximo recomendado '.($disk['suggested'] === null ? 'desconocido' : $fmt($disk['suggested']))">
                            {{ $disk['limit'] === null ? 'Sin configurar' : $fmt($disk['limit']) }}
                        </x-ui::td>
                        @if ($o['canManagePools'])
                            <x-ui::td label="Cambiar">
                                @if ($disk['editable'])
                                    <x-ui::size-picker compact id="pool-{{ $disk['id'] }}" :presets="$o['presets']" :units="$o['units']" :selected="$disk['limit']" label="Límite del portal en este disco"
                                        :extra="$disk['suggested'] !== null && $disk['suggested'] > 0 ? [$disk['suggested'] => 'Todo lo recomendado ('.$fmt($disk['suggested']).')', 0 => 'No usar este disco'] : [0 => 'No usar este disco']"
                                        :submit-label="'Guardar límite del disco '.$disk['id']" :error="$errors->first('pool_'.$disk['id'])"
                                        x-on:size-chosen="$wire.savePool('{{ $disk['id'] }}', $event.detail)" />
                                @endif
                            </x-ui::td>
                        @endif
                    </tr>
                @empty
                    <x-ui::table-empty colspan="4">Aún no hay discos preparados. Se conectan una vez en el servidor (docs/STORAGE.md).</x-ui::table-empty>
                @endforelse
            </x-ui::table>
            @foreach ($o['disks'] as $disk)
                @if ($disk['staleCount'] > 0)
                    <x-ui::notice tone="info" wire:key="stale-{{ $disk['id'] }}">
                        {{ $disk['id'] }}: {{ $disk['staleCount'] }} {{ $disk['staleCount'] === 1 ? 'subida interrumpida sigue' : 'subidas interrumpidas siguen' }} ocupando {{ $fmt($disk['staleAutomatic'] + $disk['staleManual']) }}.
                        @if ($disk['staleAutomatic'] > 0) {{ $fmt($disk['staleAutomatic']) }} se liberarán solos en unos minutos. @endif
                        @if ($disk['staleManual'] > 0) {{ $fmt($disk['staleManual']) }} no tienen limpieza automática (docs/STORAGE.md, «Reconciliación»). @endif
                    </x-ui::notice>
                @endif
                @if ($disk['limitAboveCapacity'])
                    <x-ui::notice tone="warn" wire:key="above-{{ $disk['id'] }}">{{ $disk['id'] }}: el límite supera el espacio disponible; las personas podrían quedarse sin sitio antes de llegar a su máximo.</x-ui::notice>
                @endif
            @endforeach
        </x-ui::stack>

        <x-ui::stack gap="sm" aria-labelledby="people">
            <x-ui::heading id="people">Personas</x-ui::heading>
            <x-ui::toolbar>
                <x-ui::field name="q" type="search" label="Buscar por nombre, usuario o correo" maxlength="100" wire:model.live.debounce.300ms="search" />
                @if ($o['canManagePools'])
                    <x-slot:end>
                        <x-ui::size-picker compact id="default" :presets="$o['presets']" :units="$o['units']" :selected="$o['default']" :label="'Por defecto para personas nuevas ('.$fmt($o['default']).')'" visible-label
                            submit-label="Guardar espacio por defecto" :error="$errors->first('default')" x-on:size-chosen="$wire.saveDefault($event.detail)" />
                    </x-slot:end>
                @endif
            </x-ui::toolbar>
            <x-ui::table label="Espacio por persona" :paginator="$o['users']">
                <x-slot:head><tr><th>Persona</th><th>Uso</th><th>Espacio máximo</th></tr></x-slot:head>
                @forelse ($o['rows'] as $row)
                    <tr wire:key="quota-{{ $row['user']->id }}" id="user-{{ $row['user']->id }}">
                        <x-ui::td primary :sub="$row['user']->roleLabel().($row['followsDefault'] ? ' · espacio por defecto' : '')">{{ $row['user']->name }}</x-ui::td>
                        <x-ui::td label="Uso">
                            <x-ui::meter :value="$row['percent']" :label="'Espacio usado por '.$row['user']->name">
                                {{ $fmt($row['used']) }} de {{ $fmt($row['limit']) }}@if ($row['reserved'] > 0) · {{ $fmt($row['reserved']) }} subiendo @endif @if ($row['limit'] === 0) · bloqueado @endif
                            </x-ui::meter>
                        </x-ui::td>
                        <x-ui::td label="Máximo">
                            @if ($row['canManage'])
                                <x-ui::size-picker compact id="quota-{{ $row['user']->id }}" :presets="$o['presets']" :units="$o['units']" :selected="$row['followsDefault'] ? null : $row['limit']"
                                    :label="'Espacio máximo de '.$row['user']->name" :submit-label="'Guardar espacio de '.$row['user']->name" :error="$errors->first('quota_'.$row['user']->id)"
                                    x-on:size-chosen="$wire.saveUser({{ $row['user']->id }}, $event.detail)" />
                            @else
                                {{ $fmt($row['limit']) }}
                            @endif
                        </x-ui::td>
                    </tr>
                @empty
                    <x-ui::table-empty colspan="3">No hay personas que coincidan con la búsqueda.</x-ui::table-empty>
                @endforelse
            </x-ui::table>
        </x-ui::stack>

        <x-ui::stack gap="sm" aria-labelledby="hardware">
            <x-ui::heading id="hardware">Discos del servidor</x-ui::heading>
            @if ($o['hardware'] === null)
                <x-ui::text tone="muted">Ejecuta <code>make nas-discover</code> en el servidor para ver aquí sus discos y particiones.</x-ui::text>
            @else
                <x-ui::text tone="muted">Detectados el {{ $o['hardware']['generated_at'] }}{{ $o['hardware']['host'] !== '' ? ' en '.$o['hardware']['host'] : '' }}. Solo lectura.</x-ui::text>
                <x-ui::table label="Discos y particiones del servidor">
                    <x-slot:head><tr><th>Partición</th><th>Formato</th><th>Tamaño</th><th>Montaje</th><th>Portal</th></tr></x-slot:head>
                    @forelse ($o['hardware']['disks'] as $device)
                        @php($deviceLabel = ($device['model'] !== '' ? $device['model'] : 'Disco').' · '.$device['name'].' · '.$fmt($device['size']).($device['removable'] ? ' · extraíble' : ''))
                        @forelse ($device['partitions'] as $part)
                            <tr wire:key="part-{{ $device['name'] }}-{{ $part['name'] }}">
                                <x-ui::td primary :sub="$deviceLabel">{{ $part['name'] }}</x-ui::td>
                                <x-ui::td label="Formato">{{ $part['fstype'] !== '' ? $part['fstype'] : 'sin formato' }}</x-ui::td>
                                <x-ui::td label="Tamaño" nowrap>{{ $fmt($part['size']) }}</x-ui::td>
                                <x-ui::td label="Montaje" :sub="$part['fs_available'] !== null ? 'libre '.$fmt($part['fs_available']) : null">{{ $part['mountpoint'] === '' ? 'No montada' : $part['mountpoint'] }}</x-ui::td>
                                <x-ui::td label="Portal">@if ($part['in_use'])<x-ui::status>En uso por el portal</x-ui::status>@else — @endif</x-ui::td>
                            </tr>
                        @empty
                            <tr wire:key="part-{{ $device['name'] }}">
                                <x-ui::td primary :sub="$deviceLabel">{{ $device['name'] }}</x-ui::td>
                                <x-ui::td colspan="4">Sin particiones con sistema de archivos (disco sin usar).</x-ui::td>
                            </tr>
                        @endforelse
                    @empty
                        <x-ui::table-empty colspan="5">La última detección no encontró discos.</x-ui::table-empty>
                    @endforelse
                </x-ui::table>
            @endif
        </x-ui::stack>
    </x-ui::stack>
</div>
