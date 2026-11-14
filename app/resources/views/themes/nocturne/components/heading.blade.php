@props(['level' => 2])
{{-- Contract: section heading below the page title. level 2 | 3. --}}
@php($tag = $level === 3 ? 'h3' : 'h2')
<{{ $tag }} {{ $attributes->merge(['class' => $level === 3 ? 'ui-h3' : 'ui-h2']) }}>{{ $slot }}</{{ $tag }}>
