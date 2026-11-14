@props(['name', 'title', 'description' => null])
{{--
    Contract: modal dialog (native <dialog>) opened and closed by name with the browser
    events `ui-dialog-open` / `ui-dialog-close` (detail: the name, or {name}). Livewire can
    close it with $this->dispatch('ui-dialog-close', name: '…'). Extra attributes go to the
    <dialog> (e.g. x-on:close="$wire.reset()"). Livewire updates keep it open (wire:ignore.self).
--}}
<dialog wire:ignore.self class="ui-dialog" aria-label="{{ $title }}"
    x-data="{ is(event) { return (event.detail?.name ?? event.detail) === @js($name) } }"
    x-on:ui-dialog-open.window="is($event) && ! $el.open && $el.showModal()"
    x-on:ui-dialog-close.window="is($event) && $el.open && $el.close()"
    x-on:click.self="$el.close()" {{ $attributes }}>
    <div class="ui-dialog-body">
        <h2 class="ui-h2">{{ $title }}</h2>
        @if ($description)<p class="ui-sub">{{ $description }}</p>@endif
        {{ $slot }}
    </div>
</dialog>
