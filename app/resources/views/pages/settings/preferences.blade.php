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

    public string $start = 'services';

    public string $per_page = '25';

    public string $assistant_button = 'on';

    public function mount(): void
    {
        $this->fill(array_intersect_key(Auth::user()->preferences()->toArray(), UserPreferences::rules()));
    }

    /** Every change is saved at once: no save button, no page reload. */
    public function updated(): void
    {
        $data = $this->validate(UserPreferences::rules());
        $data['per_page'] = (int) $data['per_page'];
        $user = Auth::user();
        // Keys set on other pages (e.g. the Mis archivos view) are kept.
        $user->preferences = UserPreferences::from($data + $user->preferences()->toArray())->toArray();
        $user->save();
        $this->applyHtmlAttributes($user->preferences()->htmlAttributes());
        $this->toast('Preferencias guardadas');
    }
}; ?>

<x-pages::settings.layout heading="Preferencias" subheading="Ajusta Multisuite a tu manera. Los cambios se guardan al momento y solo te afectan a ti.">
    <x-ui::stack gap="lg">
        <x-ui::select name="appearance" label="Tema de este dispositivo"
            x-data x-init="$el.value = window.multisuiteTheme.choice"
            x-on:change="window.multisuiteTheme.select($event.target.value)"
            :options="['system' => 'Según el sistema', 'light' => 'Claro', 'dark' => 'Oscuro']" />
        <x-ui::choice-group name="motion" label="Animaciones" wire:model.live="motion"
            help="«Según el sistema» respeta la opción de reducir movimiento de tu dispositivo."
            :options="['system' => 'Según el sistema', 'on' => 'Activadas', 'off' => 'Desactivadas']" />

        <x-ui::choice-group name="density" label="Densidad" wire:model.live="density"
            help="Compacta muestra más información en pantalla con menos espacio entre elementos."
            :options="['comfortable' => 'Cómoda', 'compact' => 'Compacta']" />

        <x-ui::choice-group name="start" label="Página de inicio" wire:model.live="start"
            help="Lo primero que verás al entrar."
            :options="['services' => 'Servicios', 'files' => 'Mis archivos']" />

        <x-ui::choice-group name="per_page" label="Elementos por página" wire:model.live="per_page"
            help="En listas como Personas, Invitaciones y Actividad."
            :options="[10 => '10', 25 => '25', 50 => '50']" />

        @can('use-assistant')
            <x-ui::choice-group name="assistant_button" label="Botón del asistente" wire:model.live="assistant_button"
                help="Un botón redondo abajo a la derecha abre el asistente en cualquier página. Si te molesta, ocúltalo: el asistente sigue en el menú."
                :options="['on' => 'Mostrar', 'off' => 'Ocultar']" />
        @endcan
    </x-ui::stack>
</x-pages::settings.layout>
