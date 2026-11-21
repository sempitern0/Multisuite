@props(['selected', 'hasPhoto'])
{{--
    Character picker for the profile picture. Calls selectSticker(id|null) on the page.
    With a photo, choosing asks first: the photo is deleted and cannot be recovered.
    wire:ignore.self keeps the panel open while the choices re-render.
--}}
<details class="ui-sticker-picker" wire:ignore.self
    x-data="{
        pending: undefined,
        pick(id) {
            // Read from a child: the details element itself is ignored by Livewire re-renders.
            if (this.$refs.grid.dataset.hasPhoto !== '1') return $wire.selectSticker(id);
            this.pending = id;
            this.$refs.confirm.showModal();
        },
        confirm() {
            this.$refs.confirm.close();
            $wire.selectSticker(this.pending);
        },
    }">
    <summary class="ui-btn ui-btn-secondary">Elegir un personaje</summary>
    <div class="ui-sticker-grid" role="group" aria-label="Personajes" x-ref="grid" data-has-photo="{{ $hasPhoto ? '1' : '0' }}">
        @foreach (\App\Support\ProfileStickers::LABELS as $id => $label)
            <button type="button" class="ui-sticker-choice" wire:key="sticker-{{ $id }}" x-on:click="pick(@js($id))"
                wire:loading.attr="disabled" wire:target="selectSticker" aria-pressed="{{ $selected === $id ? 'true' : 'false' }}">
                <img src="{{ \App\Support\ProfileStickers::url($id) }}" alt="" width="64" height="64" loading="lazy" decoding="async">
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
    <button type="button" class="ui-btn ui-btn-ghost" x-on:click="pick(null)" wire:loading.attr="disabled" wire:target="selectSticker">Usar mis iniciales</button>
    @error('sticker')<p class="ui-error" role="alert">{{ $message }}</p>@enderror

    <dialog x-ref="confirm" class="ui-dialog" aria-label="¿Quitar tu foto?" x-on:click.self="$refs.confirm.close()">
        <div class="ui-dialog-body">
            <h2 class="ui-h2">¿Quitar tu foto?</h2>
            <p class="ui-sub">Tu foto se borrará y no se podrá recuperar. Podrás subir otra cuando quieras.</p>
            <div class="ui-dialog-actions">
                <button type="button" class="ui-btn ui-btn-ghost" x-on:click="$refs.confirm.close()">Cancelar</button>
                <button type="button" class="ui-btn ui-btn-danger" x-on:click="confirm()">Quitar foto y cambiar</button>
            </div>
        </div>
    </dialog>
</details>
