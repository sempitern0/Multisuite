@props(['name', 'label', 'checked' => false, 'value' => '1'])
{{-- Contract: labelled checkbox; shows the validation error for `name` (without [] suffix) below it. --}}
@php($error = $errors->first(rtrim($name, '[]')))
<div>
    <label class="ui-check">
        <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }}>
        <span>{{ $label }}</span>
    </label>
    @if ($error)<p class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
