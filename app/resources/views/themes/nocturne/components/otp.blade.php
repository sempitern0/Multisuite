@props(['name', 'label', 'length' => 6, 'id' => null])
{{--
    Contract: one-time code as a single native field (replaces flux:otp). One input is what
    password managers, SMS autofill (autocomplete="one-time-code") and screen readers handle
    best; the digits are spaced by CSS. Shows the validation error for `name`.
--}}
@php
    $id ??= 'otp-'.$name;
    $error = $errors->first($name);
@endphp
<div class="ui-field ui-otp">
    <label class="ui-sr-only" for="{{ $id }}">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="text" inputmode="numeric" autocomplete="one-time-code" spellcheck="false"
        maxlength="{{ $length }}" pattern="[0-9]{{ '{'.$length.'}' }}" placeholder="{{ str_repeat('·', $length) }}"
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        x-on:input="$el.value = $el.value.replace(/\D/g, '').slice(0, {{ (int) $length }})"
        {{ $attributes->merge(['class' => 'ui-input ui-otp-input', 'style' => '--otp-length: '.(int) $length]) }}>
    @if ($error)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
