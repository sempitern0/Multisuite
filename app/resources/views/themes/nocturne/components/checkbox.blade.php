@props(['name', 'label', 'checked' => false, 'value' => '1', 'description' => null, 'switch' => false])
{{--
    Contract: labelled checkbox shown as a full-width row (the whole row toggles it).
    switch: on/off setting that applies as a whole (role="switch"); otherwise a choice in a list.
    description: one line under the label, announced as a description, not as part of the name.
    Shows the validation error for `name` (without [] suffix) below it.
--}}
@php
    $error = $errors->first(rtrim($name, '[]'));
    $id = 'chk-'.str_replace(['[', ']'], '', $name).'-'.\Illuminate\Support\Str::slug((string) $value);
@endphp
<div class="ui-check" @if ($switch) data-variant="switch" @endif>
    <label class="ui-check-main">
        <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) @if ($switch) role="switch" @endif
            @if ($description) aria-describedby="{{ $id }}-desc" @endif {{ $attributes }}>
        <span class="ui-check-label">{{ $label }}</span>
    </label>
    @if ($description)<p id="{{ $id }}-desc" class="ui-check-desc">{{ $description }}</p>@endif
    @if ($error)<p class="ui-error" role="alert">{{ $error }}</p>@endif
</div>
