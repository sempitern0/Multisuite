@props(['selected', 'hasPhoto'])

<details class="ui-sticker-picker">
    <summary class="ui-btn ui-btn-secondary">Elegir un sticker</summary>
    @if ($hasPhoto)
        <p>Al elegir un avatar se quitará tu foto actual.</p>
    @endif
    <div class="ui-sticker-grid" role="group" aria-label="Avatares de perfil">
        @foreach (\App\Support\ProfileStickers::LABELS as $id => $label)
            <button type="button" class="ui-sticker-choice" wire:key="sticker-{{ $id }}" wire:click="selectSticker('{{ $id }}')"
                wire:loading.attr="disabled" wire:target="selectSticker" aria-pressed="{{ $selected === $id ? 'true' : 'false' }}">
                <img src="{{ \App\Support\ProfileStickers::url($id) }}" alt="" width="64" height="64" loading="lazy" decoding="async">
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
    <x-ui::button variant="ghost" wire:click="selectSticker(null)" wire:loading.attr="disabled" wire:target="selectSticker">Usar mis iniciales</x-ui::button>
    @error('sticker')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
</details>
