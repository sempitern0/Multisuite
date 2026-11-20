@props(['name', 'label', 'help' => null, 'id' => null, 'rows' => 3])
{{-- Contract: labelled multi-line input with the validation error for `name`. --}}
@php
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="ui-field">
    <label class="ui-label" for="{{ $id }}">{{ $label }}</label>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($error) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge(['class' => 'ui-input ui-textarea']) }}>{{ old($name) }}</textarea>
    @if ($help)<p id="{{ $id }}-help" class="ui-help">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
