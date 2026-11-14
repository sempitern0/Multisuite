@props(['storeUrl', 'uploadUrl', 'maxBytes', 'folder' => null, 'label' => 'Subir archivos', 'hint' => null])
{{--
    Contract: file picker driven by resources/js/files.js (data-uploader* hooks) with a
    progress list. Livewire must not touch it while uploads run (wire:ignore); the script
    dispatches `files-uploaded` when it finishes.
--}}
<div wire:ignore data-uploader data-store-url="{{ $storeUrl }}" data-upload-url="{{ $uploadUrl }}" data-folder="{{ $folder }}" data-max-bytes="{{ $maxBytes }}" data-csrf="{{ csrf_token() }}" {{ $attributes->merge(['class' => 'ui-uploader']) }}>
    <label class="ui-btn ui-btn-primary ui-uploader-pick">
        <x-ui::icon name="upload" /><span>{{ $label }}</span>
        <input type="file" multiple class="ui-sr-only" data-uploader-input>
    </label>
    @if ($hint)<p class="ui-help">{{ $hint }}</p>@endif
    <ol data-uploader-list class="ui-uploads" aria-live="polite"></ol>
</div>
