@props(['title', 'eyebrow' => null, 'description' => null])
{{-- Contract: page title (h1) with optional eyebrow, description and `actions` slot. --}}
<header class="ui-page-header">
    <div>
        @if ($eyebrow)<p class="ui-eyebrow">{{ $eyebrow }}</p>@endif
        <h1 class="ui-h1">{{ $title }}</h1>
        @if ($description)<p class="ui-sub">{{ $description }}</p>@endif
    </div>
    @isset($actions)<div class="ui-actions">{{ $actions }}</div>@endisset
</header>
