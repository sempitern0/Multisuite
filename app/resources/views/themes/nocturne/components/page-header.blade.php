@props(['title', 'eyebrow' => null, 'description' => null, 'back' => null, 'backLabel' => 'Atrás'])
{{--
    Contract: page title (h1) with optional eyebrow, description and `actions` slot.
    back = URL of the parent screen: draws a back arrow before the title (SPA navigation);
    backLabel names where it goes ("Volver a …").
--}}
<header class="ui-page-header">
    <div class="ui-page-heading">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="ui-back" title="Volver a {{ $backLabel }}" aria-label="Volver a {{ $backLabel }}"><x-ui::icon name="arrow-left" /></a>
        @endif
        <div class="ui-page-titles">
            @if ($eyebrow)<p class="ui-eyebrow">{{ $eyebrow }}</p>@endif
            <h1 class="ui-h1">{{ $title }}</h1>
            @if ($description)<p class="ui-sub">{{ $description }}</p>@endif
        </div>
    </div>
    @isset($actions)<div class="ui-actions">{{ $actions }}</div>@endisset
</header>
