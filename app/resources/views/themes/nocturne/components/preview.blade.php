@props(['kind', 'src' => null, 'title' => '', 'note' => null])
{{--
    Contract: read-only preview surface. kind image|pdf show `src`; kind text shows the
    slot as preformatted text; kind rich shows the slot as is; kind none shows the slot as a message.
--}}
<section aria-label="Vista previa" {{ $attributes->merge(['class' => 'ui-panel ui-preview', 'data-kind' => $kind]) }}>
    @if ($kind === 'image')
        <img src="{{ $src }}" alt="{{ $title }}">
    @elseif ($kind === 'pdf')
        <iframe src="{{ $src }}" title="Vista previa de {{ $title }}"></iframe>
    @elseif ($kind === 'text')
        <pre>{{ $slot }}</pre>
    @else
        <div class="ui-preview-body">{{ $slot }}</div>
    @endif
    @if ($note)<p class="ui-preview-note">{{ $note }}</p>@endif
</section>
