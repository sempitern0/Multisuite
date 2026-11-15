@props(['storeUrl', 'uploadUrl', 'maxBytes', 'folder' => null, 'label' => 'Subir archivos', 'hint' => null])
{{--
    Contract: file picker and drop zone driven by resources/js/files.js (data-uploader* and
    data-tray* hooks). Progress appears in a floating tray: one summary line with the total
    progress, an expandable list (errors first) and a close button when everything ends.
    The script fills the tray by cloning <template data-tray-row> and only sets data-state
    (queued|uploading|ok|bad), texts and progress values; this file decides how it looks.
    Livewire must not touch it while uploads run (wire:ignore); the script dispatches
    `files-uploaded` with the new file ids as files finish.
--}}
<div wire:ignore data-uploader data-store-url="{{ $storeUrl }}" data-upload-url="{{ $uploadUrl }}" data-folder="{{ $folder }}" data-max-bytes="{{ $maxBytes }}" data-csrf="{{ csrf_token() }}" {{ $attributes->merge(['class' => 'ui-uploader']) }}>
    <label class="ui-btn ui-btn-primary ui-uploader-pick" title="{{ $hint ? $hint.' ' : '' }}También puedes arrastrar archivos a esta página.">
        <x-ui::icon name="upload" /><span>{{ $label }}</span>
        <input type="file" multiple class="ui-sr-only" data-uploader-input>
    </label>
    <div class="ui-dropzone" data-uploader-drop hidden aria-hidden="true">
        <x-ui::icon name="upload" /><span>Suelta los archivos para subirlos aquí</span>
    </div>

    <section class="ui-tray" data-tray hidden aria-labelledby="upload-tray-title">
        <header class="ui-tray-head">
            <span class="ui-tray-badge" data-tray-badge aria-hidden="true"><x-ui::icon name="upload" /><x-ui::icon name="check" /><x-ui::icon name="alert" /></span>
            <div class="ui-tray-summary">
                <h2 id="upload-tray-title" class="ui-tray-title" data-tray-title aria-live="polite">Subiendo…</h2>
                <p class="ui-tray-detail" data-tray-detail></p>
            </div>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm ui-btn-icon ui-tray-toggle" data-tray-toggle aria-expanded="false" aria-controls="upload-tray-list" title="Ver archivos" aria-label="Ver archivos"><x-ui::icon name="chevron-right" /></button>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm ui-btn-icon" data-tray-close hidden title="Cerrar" aria-label="Cerrar el resumen de subidas"><x-ui::icon name="close" /></button>
        </header>
        <div class="ui-tray-bar" aria-hidden="true"><span data-tray-bar></span></div>
        <ol class="ui-tray-list" id="upload-tray-list" data-tray-list hidden></ol>
    </section>
    <template data-tray-row>
        <li class="ui-tray-row" data-state="queued">
            <span class="ui-tray-state" aria-hidden="true"><span class="ui-tray-spinner"></span><x-ui::icon name="check" /><x-ui::icon name="alert" /></span>
            <span class="ui-tray-name" data-row-name></span>
            <span class="ui-tray-meta" data-row-meta></span>
            <span class="ui-tray-error" data-row-error></span>
            <span class="ui-tray-rowbar" aria-hidden="true"><span data-row-bar></span></span>
        </li>
    </template>
</div>
