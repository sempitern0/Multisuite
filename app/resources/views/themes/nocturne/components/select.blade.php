@props(['name', 'label', 'options', 'placeholder' => null, 'help' => null, 'id' => null])
{{-- Contract: labelled select over value => text options; optional first empty option. --}}
@php
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
@endphp
<div class="ui-field">
    <label class="ui-label" for="{{ $id }}">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif {{ $attributes->merge(['class' => 'ui-input ui-select']) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) old($name, $attributes->get('value')) === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($help)<p class="ui-help">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
