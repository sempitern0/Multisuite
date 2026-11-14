{{-- Contract: long-form content already rendered to safe HTML (e.g. sanitised Markdown). --}}
<article {{ $attributes->merge(['class' => 'ui-prose']) }}>{{ $slot }}</article>
