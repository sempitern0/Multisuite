@props(['title', 'icon', 'description', 'status' => null, 'tone' => 'muted'])
<details wire:ignore.self {{ $attributes->class('ui-security-option') }}>
    <summary>
        <span class="ui-security-symbol"><x-ui::icon :name="$icon" /></span>
        <span class="ui-security-copy">
            <strong>{{ $title }}</strong>
            <span>{{ $description }}</span>
        </span>
        @if ($status !== null)
            <x-ui::status :tone="$tone">{{ $status }}</x-ui::status>
        @endif
        <x-ui::icon class="ui-security-chevron" name="chevron-right" />
    </summary>
    <div class="ui-security-body">{{ $slot }}</div>
</details>
