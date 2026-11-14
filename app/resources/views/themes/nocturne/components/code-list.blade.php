@props(['items', 'label'])
{{-- Contract: short list of codes to copy by hand (monospace, selectable). --}}
<ul {{ $attributes->merge(['class' => 'ui-codes']) }} aria-label="{{ $label }}">
    @foreach ($items as $item)
        <li>{{ $item }}</li>
    @endforeach
</ul>
