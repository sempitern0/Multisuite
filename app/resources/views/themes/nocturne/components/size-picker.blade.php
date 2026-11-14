@props(['id', 'presets', 'units', 'selected' => null, 'extra' => [], 'label' => 'Espacio máximo', 'submitLabel' => 'Guardar', 'error' => null])
{{--
    Contract: size chosen from presets or "another amount" (amount + unit appear only then).
    On submit it dispatches `size-chosen` with {preset, amount, unit}; the page decides
    what to do (e.g. x-on:size-chosen="$wire.save($event.detail)").
--}}
@php
    $known = $selected !== null && (array_key_exists($selected, $presets) || array_key_exists($selected, $extra));
    $initial = $selected === null ? '' : ($known ? (string) $selected : 'custom');
@endphp
<form {{ $attributes->merge(['class' => 'ui-size-picker']) }} x-data="{ preset: @js($initial), amount: '', unit: 'GB' }"
    x-on:submit.prevent="$dispatch('size-chosen', { preset, amount, unit })">
    <div class="ui-field">
        <label class="ui-label" for="{{ $id }}-preset">{{ $label }}</label>
        <select id="{{ $id }}-preset" class="ui-input ui-select" x-model="preset" required @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
            <option value="">Elige una cantidad…</option>
            @foreach ($extra as $bytes => $text)
                <option value="{{ $bytes }}">{{ $text }}</option>
            @endforeach
            @foreach ($presets as $bytes => $text)
                @continue(array_key_exists($bytes, $extra))
                <option value="{{ $bytes }}">{{ $text }}</option>
            @endforeach
            <option value="custom">Otra cantidad…</option>
        </select>
    </div>
    <div class="ui-field" x-show="preset === 'custom'" x-cloak>
        <label class="ui-label" for="{{ $id }}-amount">Cantidad</label>
        <input id="{{ $id }}-amount" class="ui-input" x-model="amount" inputmode="decimal" maxlength="12" placeholder="Ej. 75 o 1,5" x-bind:required="preset === 'custom'">
    </div>
    <div class="ui-field" x-show="preset === 'custom'" x-cloak>
        <label class="ui-label" for="{{ $id }}-unit">Unidad</label>
        <select id="{{ $id }}-unit" class="ui-input ui-select" x-model="unit">
            @foreach ($units as $unit)
                <option value="{{ $unit }}">{{ $unit }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="ui-btn ui-btn-secondary" @if ($submitLabel !== 'Guardar') aria-label="{{ $submitLabel }}" @endif>Guardar</button>
    @if ($error)<p id="{{ $id }}-error" class="ui-error ui-size-picker-error" role="alert">{{ $error }}</p>@endif
</form>
