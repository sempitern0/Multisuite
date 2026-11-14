@props(['title', 'cancelLabel' => 'Cancelar'])
{{--
    Contract: confirmation dialog (native <dialog>: focus trap and Escape for free).
    Slots: `trigger` (what opens it), default (explanation) and `action` (the button
    that performs the operation, e.g. with wire:click); the dialog closes on action.
--}}
<span x-data>
    <span x-on:click="$refs.dialog.showModal()">{{ $trigger }}</span>
    <dialog x-ref="dialog" class="ui-dialog" aria-label="{{ $title }}" x-on:click.self="$refs.dialog.close()">
        <div class="ui-dialog-body">
            <h2 class="ui-h2">{{ $title }}</h2>
            <p class="ui-sub">{{ $slot }}</p>
            <div class="ui-dialog-actions">
                <button type="button" class="ui-btn ui-btn-ghost" x-on:click="$refs.dialog.close()">{{ $cancelLabel }}</button>
                <span x-on:click="$refs.dialog.close()">{{ $action }}</span>
            </div>
        </div>
    </dialog>
</span>
