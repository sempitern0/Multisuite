@props(['icon' => 'info', 'title'])
<div {{ $attributes->merge(['class' => 'ui-panel ui-empty']) }}>
    <div class="ui-empty-icon"><x-ui::icon :name="$icon" /></div>
    <p class="ui-h2">{{ $title }}</p>
    @if (trim((string) $slot) !== '')<p class="ui-sub" style="margin-top:8px">{{ $slot }}</p>@endif
</div>
