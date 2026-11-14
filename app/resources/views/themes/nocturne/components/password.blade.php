@props(['name', 'label', 'help' => null, 'id' => null])
{{-- Contract: password input with a show/hide toggle; the value is never echoed back. --}}
@php
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
@endphp
<div class="ui-field" x-data="{ shown: false }">
    <label class="ui-label" for="{{ $id }}">{{ $label }}</label>
    <div class="ui-inputrow">
        <input id="{{ $id }}" name="{{ $name }}" type="password" x-bind:type="shown ? 'text' : 'password'"
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->merge(['class' => 'ui-input']) }}>
        <button type="button" class="ui-reveal" x-on:click="shown = ! shown" x-bind:aria-pressed="shown.toString()" aria-label="Mostrar u ocultar la contraseña">
            <x-ui::icon name="eye" x-show="! shown" />
            <x-ui::icon name="eye-off" x-show="shown" x-cloak />
        </button>
    </div>
    @if ($help)<p class="ui-help">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
