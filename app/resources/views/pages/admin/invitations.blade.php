<?php

use App\Actions\Invitations\IssueInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\Invitation;
use App\Services\ServiceAccounts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invitaciones')] class extends Component {
    use NotifiesInterface, ThrottlesActions, WithPagination;

    public string $email = '';

    public string $role = 'member';

    /** @var list<string> */
    public array $services = [];

    #[Url(except: '')]
    public string $status = '';

    public function boot(): void
    {
        Gate::authorize('manage-invitations');
    }

    public function mount(): void
    {
        $this->services = $this->availableServices;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** @return list<string> */
    #[Computed]
    public function roles(): array
    {
        return Auth::user()->role === 'owner' ? ['member', 'admin'] : ['member'];
    }

    /** @return list<string> */
    #[Computed]
    public function availableServices(): array
    {
        return app(ServiceAccounts::class)->available();
    }

    #[Computed]
    public function invitations(): LengthAwarePaginator
    {
        return Invitation::whereIn('role', $this->roles)
            ->with('inviter')
            ->inStatus($this->status)
            ->latest()
            ->paginate(Auth::user()->preferences()->perPage());
    }

    public function invite(IssueInvitation $issue): void
    {
        $this->throttle('admin');
        /** @var array{email: string, role: string, services?: list<string>} $data */
        $data = $this->validate($issue->rules());
        $issue->handle(Auth::user(), $data);
        $this->reset('email');
        $this->role = 'member';
        $this->resetPage();
        $this->toast('Invitación enviada a '.$data['email']);
    }

    public function revoke(int $invitationId, RevokeInvitation $revoke): void
    {
        $this->throttle('admin');
        $invitation = Invitation::whereIn('role', $this->roles)->findOrFail($invitationId);
        $revoke->handle(Auth::user(), $invitation);
        $this->toast('Invitación revocada');
    }
}; ?>

<div>
    <x-ui::page-header eyebrow="Administración" title="Invitaciones" description="Invita a tu familia y conocidos. Cada enlace caduca en 7 días y solo sirve para una cuenta." />

    <x-ui::stack gap="lg">
        <x-ui::panel aria-labelledby="new-invitation">
            <x-ui::stack gap="md">
                <x-ui::heading id="new-invitation">Nueva invitación</x-ui::heading>
                <x-ui::form wire:submit="invite">
                    <x-ui::field name="email" type="email" label="Correo de la persona invitada" wire:model="email" required autocomplete="off" />
                    @if (count($this->roles) > 1)
                        <x-ui::choice-group name="role" label="Rol de la cuenta" wire:model="role"
                            help="Solo el propietario puede invitar administradores."
                            :options="['member' => 'Miembro', 'admin' => 'Administrador']" />
                    @endif
                    @foreach ($this->availableServices as $service)
                        <x-ui::checkbox name="services[]" :value="$service" wire:model="services" :label="'Preparar su cuenta en '.config('portal.services.'.$service.'.name')" />
                    @endforeach
                    <x-ui::button type="submit" icon="mail">Enviar invitación</x-ui::button>
                </x-ui::form>
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::stack gap="md">
            <x-ui::toolbar>
                <x-ui::choice-group name="status" label="Mostrar" wire:model.live="status"
                    :options="['' => 'Todas', 'pending' => 'Pendientes', 'accepted' => 'Aceptadas', 'expired' => 'Caducadas o anuladas']" />
            </x-ui::toolbar>

            <x-ui::table label="Invitaciones emitidas" :paginator="$this->invitations">
                <x-slot:head>
                    <tr><th>Correo</th><th>Rol</th><th>Estado</th><th>Caduca</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr>
                </x-slot:head>
                @forelse ($this->invitations as $invitation)
                    @php($pending = $invitation->status() === 'pending')
                    <tr wire:key="invitation-{{ $invitation->id }}">
                        <x-ui::td primary>{{ $invitation->email }}</x-ui::td>
                        <x-ui::td label="Rol">{{ $invitation->role === 'admin' ? 'Administrador' : 'Miembro' }}</x-ui::td>
                        <x-ui::td label="Estado">
                            @if ($invitation->status() === 'accepted')
                                <x-ui::status>Aceptada</x-ui::status>
                            @elseif ($pending)
                                <x-ui::status tone="warn">Pendiente</x-ui::status>
                            @else
                                <x-ui::status tone="muted">Caducada o anulada</x-ui::status>
                            @endif
                        </x-ui::td>
                        <x-ui::td label="Caduca">{{ $pending ? $invitation->expires_at->format('d/m/Y') : '—' }}</x-ui::td>
                        <x-ui::td align="end">
                            @if ($pending)
                                <x-ui::button variant="ghost" size="sm" wire:click="revoke({{ $invitation->id }})"
                                    wire:confirm="¿Revocar la invitación de {{ $invitation->email }}? El enlace dejará de funcionar."
                                    :aria-label="'Revocar invitación de '.$invitation->email">Revocar</x-ui::button>
                            @endif
                        </x-ui::td>
                    </tr>
                @empty
                    <x-ui::table-empty colspan="5">No hay invitaciones con este filtro.</x-ui::table-empty>
                @endforelse
            </x-ui::table>
        </x-ui::stack>
    </x-ui::stack>
</div>
