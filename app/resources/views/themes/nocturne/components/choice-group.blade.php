@props(['name', 'label', 'options', 'help' => null])
{{-- Contract: single choice among a few predefined options (segmented). Pass wire:model on the group. --}}
@php
    $id = 'c-'.$name;
    $model = $attributes->whereStartsWith('wire:model');
    $error = $errors->first($name);
@endphp
<fieldset {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'ui-choice']) }} @if ($help) aria-describedby="{{ $id }}-help" @endif>
    <legend class="ui-label">{{ $label }}</legend>
    <div class="ui-choice-options">
        @foreach ($options as $value => $text)
            <label class="ui-choice-option">
                <input type="radio" name="{{ $name }}" value="{{ $value }}" {{ $model }}>
                <span>{{ $text }}</span>
            </label>
        @endforeach
    </div>
    @if ($help)<p id="{{ $id }}-help" class="ui-help">{{ $help }}</p>@endif
    @if ($error)<p class="ui-error" role="alert">{{ $error }}</p>@endif
</fieldset>
