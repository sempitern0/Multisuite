@props(['model' => 'avatar', 'save' => 'saveAvatar', 'src' => null, 'initials' => ''])
{{--
    Contract: choose a photo and frame it (drag to move, slider, Ctrl/⌘ + wheel or pinch to zoom,
    arrow keys and + / −) inside a circle. The browser crops it to a 512 px square and uploads it to
    the Livewire property `model`, then calls the `save` method. The server re-processes whatever
    arrives, so this is convenience, not a security boundary. Default slot: extra actions next to
    "Cambiar foto" (e.g. removing it). Errors for `model` are shown under the editor.
--}}
<div {{ $attributes->merge(['class' => 'ui-avatar-editor']) }} x-data="avatarEditor(@js(['model' => $model, 'save' => $save]))">
    <div class="ui-avatar-editor-current" x-show="! editing">
        <x-ui::avatar :src="$src" :initials="$initials" size="xl" />
        <div class="ui-avatar-editor-actions">
            <label class="ui-btn ui-btn-secondary">
                <x-ui::icon name="image" />{{ $src ? 'Cambiar foto' : 'Elegir foto' }}
                <input type="file" class="ui-sr-only" accept="image/jpeg,image/png,image/webp,image/gif" x-on:change="choose($event)">
            </label>
            {{ $slot }}
        </div>
    </div>

    <div class="ui-avatar-editor-crop" x-show="editing" x-cloak wire:ignore>
        <div class="ui-avatar-stage" x-ref="stage" tabindex="0" role="group"
             aria-label="Encuadre de la foto. Arrastra o usa las flechas para moverla; + y − para acercar o alejar."
             x-on:pointerdown="press($event)" x-on:pointermove="drag($event)" x-on:pointerup="release($event)" x-on:pointercancel="release($event)"
             x-on:wheel="wheel($event)" x-on:keydown="key($event)">
            <img x-ref="image" alt="" draggable="false">
        </div>
        <label class="ui-avatar-zoom">
            <span>Zoom</span>
            <input type="range" min="1" max="4" step="0.01" x-model.number="zoom" x-on:input="render()" aria-label="Zoom de la foto">
        </label>
        <div class="ui-avatar-editor-actions">
            <button type="button" class="ui-btn ui-btn-primary" x-on:click="upload()" x-bind:disabled="busy">
                <x-ui::icon name="check" /><span x-text="busy ? 'Guardando… ' + progress + ' %' : 'Guardar foto'">Guardar foto</span>
            </button>
            <button type="button" class="ui-btn ui-btn-ghost" x-on:click="cancel()" x-bind:disabled="busy">Cancelar</button>
        </div>
    </div>
    <p class="ui-error" role="alert" x-show="problem" x-text="problem" x-cloak></p>
    @error($model)<p class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
