@props(['open' => false, 'title', 'toggle', 'close'])
{{--
    Contract: floating launcher (bottom right) that opens a non-modal panel. `toggle` and
    `close` are the Livewire methods to call; slots: `tools` (header buttons), the default
    slot (scrolling body) and `composer` (fixed footer). Escape closes; focus moves into the
    panel when it opens. The page behind stays usable.
--}}
<div class="ui-dock" @if ($open) x-on:keydown.escape.window="$wire.{{ $close }}()" @endif>
    @if ($open)
        <section id="ui-dock-panel" class="ui-dock-panel" role="dialog" aria-modal="false" aria-labelledby="ui-dock-title">
            <header class="ui-dock-head">
                <h2 id="ui-dock-title" class="ui-dock-title">{{ $title }}</h2>
                <div class="ui-dock-tools">
                    {{ $tools ?? '' }}
                    <x-ui::button size="sm" variant="ghost" icon="close" wire:click="{{ $close }}" label="Cerrar" />
                </div>
            </header>
            <div class="ui-dock-body" x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:navigated.window="$el.scrollTop = $el.scrollHeight">{{ $slot }}</div>
            <footer class="ui-dock-foot">{{ $composer ?? '' }}</footer>
        </section>
    @endif
    <button type="button" class="ui-dock-launcher" wire:click="{{ $toggle }}" aria-expanded="{{ $open ? 'true' : 'false' }}"
        @if ($open) aria-controls="ui-dock-panel" @endif aria-label="{{ $open ? 'Cerrar el asistente' : 'Abrir el asistente' }}">
        <x-ui::icon :name="$open ? 'close' : 'diamond'" />
    </button>
</div>
