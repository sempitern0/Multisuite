@props(['items'])
{{-- Contract: a few label/value facts. items = [label => value]. --}}
<dl {{ $attributes->merge(['class' => 'ui-stats']) }}>
    @foreach ($items as $label => $value)
        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
    @endforeach
</dl>
