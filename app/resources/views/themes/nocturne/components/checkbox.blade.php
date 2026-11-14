@props(['name', 'label', 'checked' => false, 'value' => '1'])
<label class="ui-check">
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $label }}</span>
</label>
