@props(['kind', 'src' => null, 'title' => '', 'note' => null])
{{--
    Contract: read-only preview surface. kind image shows `src` in a viewer with zoom,
    drag and full screen; kind video plays `src` with the browser's player; kind pdf
    embeds `src`; kind text shows the slot as preformatted text; kind rich shows the
    slot as is; kind none shows the slot as a message. Behaviour: resources/js/viewer.js.
--}}
<section aria-label="Vista previa" {{ $attributes->merge(['class' => 'ui-panel ui-preview', 'data-kind' => $kind]) }}>
    @if ($kind === 'image')
        <div class="ui-viewer" data-viewer>
            <div class="ui-viewer-stage" data-viewer-stage tabindex="0" role="group" aria-label="{{ $title }}. Usa más y menos para acercar o alejar y las flechas para moverte.">
                <img src="{{ $src }}" alt="{{ $title }}" draggable="false" data-viewer-image>
            </div>
            <div class="ui-viewer-bar" role="toolbar" aria-label="Controles de la imagen">
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-viewer-action="out" aria-label="Alejar">−</button>
                <output class="ui-viewer-level" data-viewer-level aria-live="polite">Ajustada</output>
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-viewer-action="in" aria-label="Acercar">+</button>
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-viewer-action="fit">Ajustar</button>
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-viewer-action="actual">Tamaño real</button>
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-viewer-action="fullscreen">Pantalla completa</button>
            </div>
        </div>
    @elseif ($kind === 'video')
        <div class="ui-player" data-player>
            <video src="{{ $src }}" controls playsinline preload="metadata" aria-label="{{ $title }}"></video>
            <p class="ui-preview-body" data-player-error hidden>Este navegador no puede reproducir este formato de vídeo. Descárgalo y ábrelo con tu reproductor habitual.</p>
        </div>
    @elseif ($kind === 'pdf')
        <iframe src="{{ $src }}" title="Vista previa de {{ $title }}"></iframe>
    @elseif ($kind === 'text')
        <pre>{{ $slot }}</pre>
    @else
        <div class="ui-preview-body">{{ $slot }}</div>
    @endif
    @if ($note)<p class="ui-preview-note">{{ $note }}</p>@endif
</section>
