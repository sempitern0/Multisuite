<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferencias')] class extends Component {
    use NotifiesInterface;

    public string $motion = 'system';

    public string $density = 'comfortable';

    public string $sidebar = 'full';

    public string $start = 'services';

    public string $per_page = '25';

    public function mount(): void
    {
        $this->fill(Auth::user()->preferences()->toArray());
    }

    /** Every change is saved at once: no save button, no page reload. */
    public function updated(): void
    {
        $data = $this->validate(UserPreferences::rules());
        $data['per_page'] = (int) $data['per_page'];
        $user = Auth::user();
        $user->preferences = UserPreferences::from($data)->toArray();
        $user->save();
        $this->applyHtmlAttributes($user->preferences()->htmlAttributes());
        $this->toast('Preferencias guardadas');
    }
}; ?>

<x-pages::settings.layout heading="Preferencias" subheading="Ajusta Multisuite a tu manera. Los cambios se guardan al momento y solo te afectan a ti.">
    <x-ui::stack gap="lg">
        <x-ui::choice-group name="motion" label="Animaciones" wire:model.live="motion"
            help="«Según el sistema» respeta la opción de reducir movimiento de tu dispositivo."
            :options="['system' => 'Según el sistema', 'on' => 'Activadas', 'off' => 'Desactivadas']" />

        <x-ui::choice-group name="density" label="Densidad" wire:model.live="density"
            help="Compacta muestra más información en pantalla con menos espacio entre elementos."
            :options="['comfortable' => 'Cómoda', 'compact' => 'Compacta']" />

        <x-ui::choice-group name="sidebar" label="Menú lateral" wire:model.live="sidebar"
            help="Solo iconos deja más espacio para el contenido en pantallas grandes."
            :options="['full' => 'Completo', 'compact' => 'Solo iconos']" />

        <x-ui::choice-group name="start" label="Página de inicio" wire:model.live="start"
            help="Lo primero que verás al entrar."
            :options="['services' => 'Servicios', 'files' => 'Mis archivos']" />

        <x-ui::choice-group name="per_page" label="Elementos por página" wire:model.live="per_page"
            help="En listas como Personas, Invitaciones y Actividad."
            :options="[10 => '10', 25 => '25', 50 => '50']" />
    </x-ui::stack>
</x-pages::settings.layout>
