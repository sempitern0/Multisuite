@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null, 'id' => null, 'prefix' => null])
{{-- Contract: labelled input with server validation error for `name` (old input restored). --}}
@php
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="ui-field">
    <label class="ui-label" for="{{ $id }}">{{ $label }}</label>
    @if ($prefix !== null)<div class="ui-input-group"><span class="ui-input-prefix" aria-hidden="true">{{ $prefix }}</span>@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}"
        @if ($error) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge(['class' => 'ui-input']) }}>
    @if ($prefix !== null)</div>@endif
    @if ($help)<p id="{{ $id }}-help" class="ui-help">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
