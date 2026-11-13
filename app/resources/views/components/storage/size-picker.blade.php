@props(['presets', 'units', 'selected' => null, 'extra' => [], 'label' => 'Espacio máximo'])
{{-- Preset list for non-technical admins; "Otra cantidad" enables the free amount fields. --}}
@php($known = $selected !== null && (array_key_exists($selected, $presets) || array_key_exists($selected, $extra)))
<div class="flex flex-wrap items-end gap-2">
    <flux:select name="preset" :label="$label" required class="min-w-48">
        <flux:select.option value="" :selected="$selected === null">Elige una cantidad…</flux:select.option>
        @foreach ($extra as $bytes => $text)
            <flux:select.option :value="(string) $bytes" :selected="$selected === $bytes">{{ $text }}</flux:select.option>
        @endforeach
        @foreach ($presets as $bytes => $text)
            @continue(array_key_exists($bytes, $extra))
            <flux:select.option :value="(string) $bytes" :selected="$selected === $bytes">{{ $text }}</flux:select.option>
        @endforeach
        <flux:select.option value="custom" :selected="$selected !== null && ! $known">Otra cantidad…</flux:select.option>
    </flux:select>
    <flux:input name="amount" label="Cantidad (si eliges otra)" inputmode="decimal" maxlength="12" placeholder="Ej. 75 o 1,5" class="w-40" />
    <flux:select name="unit" label="Unidad" class="w-24">
        @foreach ($units as $unit)
            <flux:select.option :value="$unit" :selected="$unit === 'GB'">{{ $unit }}</flux:select.option>
        @endforeach
    </flux:select>
</div>
