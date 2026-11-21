@props(['model' => 'avatar', 'save' => 'saveAvatar', 'src' => null, 'initials' => ''])
{{--
    Contract: choose a photo and frame it inside a circle with Cropper.js (drag to move, zoom buttons,
    Ctrl/⌘ + wheel or pinch, arrow keys and + / −). The browser crops it to a 512 px square and uploads it to
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

    @isset($alternatives)
        <div x-show="! editing">{{ $alternatives }}</div>
    @endisset

    <div class="ui-avatar-editor-crop" x-show="editing" x-cloak wire:ignore>
        <div class="ui-avatar-stage" x-ref="stage" tabindex="0" role="group"
             aria-label="Encuadre de la foto. Arrastra o usa las flechas para moverla; + y − para acercar o alejar."
             x-on:wheel.capture="wheel($event)" x-on:keydown="key($event)"></div>
        <div class="ui-avatar-editor-actions">
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-icon" title="Alejar" aria-label="Alejar" x-on:click="zoom(-0.1)"><x-ui::icon name="zoom-out" /></button>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-icon" title="Acercar" aria-label="Acercar" x-on:click="zoom(0.1)"><x-ui::icon name="zoom-in" /></button>
        </div>
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
