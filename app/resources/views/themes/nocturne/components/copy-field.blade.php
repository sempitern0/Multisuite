@props(['value', 'label'])
{{-- Contract: read-only value with a copy button (confirms briefly when copied). --}}
<div {{ $attributes->merge(['class' => 'ui-field']) }} x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($value)); this.copied = true; setTimeout(() => this.copied = false, 1500); } catch (e) {} } }">
    <span class="ui-label">{{ $label }}</span>
    <div class="ui-inputrow">
        <input type="text" readonly value="{{ $value }}" class="ui-input" aria-label="{{ $label }}">
        <button type="button" class="ui-reveal" x-on:click="copy()" aria-label="Copiar">
            <x-ui::icon name="copy" x-show="! copied" /><x-ui::icon name="check" x-show="copied" x-cloak />
        </button>
    </div>
</div>
