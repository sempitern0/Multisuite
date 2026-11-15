@props(['href', 'name', 'icon' => 'document', 'meta' => null, 'thumb' => null, 'src' => null, 'spa' => false, 'fresh' => false])
{{--
    Contract: one tile of x-ui::file-grid. thumb image|video shows `src` as a lazy preview
    (gallery view only); otherwise the icon. Slots: `select` (checkbox), `actions` (icon buttons).
--}}
<li {{ $attributes->merge(['class' => 'ui-file']) }} @if ($fresh) data-fresh @endif>
    <a href="{{ $href }}" class="ui-file-link" title="{{ $name }}" @if ($spa) wire:navigate @endif>
        <span class="ui-file-thumb" aria-hidden="true">
            @if ($thumb === 'image' && $src)
                <img src="{{ $src }}" alt="" loading="lazy" decoding="async">
            @elseif ($thumb === 'video' && $src)
                <video src="{{ $src }}#t=0.5" preload="metadata" muted playsinline tabindex="-1"></video>
                <span class="ui-file-badge"><x-ui::icon name="video" /></span>
            @else
                <x-ui::icon :name="$icon" />
            @endif
        </span>
        <span class="ui-file-name">{{ $name }}</span>
        @if (filled($meta))<span class="ui-file-meta">{{ $meta }}</span>@endif
    </a>
    @isset($select)<span class="ui-file-select">{{ $select }}</span>@endisset
    @isset($actions)<span class="ui-file-actions">{{ $actions }}</span>@endisset
</li>
