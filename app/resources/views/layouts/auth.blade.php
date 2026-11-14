{{-- Indirection point: every auth step uses x-layouts::auth; the active theme draws it. --}}
<x-ui::layouts.auth :title="$title ?? null" :eyebrow="$eyebrow ?? null" :heading="$heading ?? null" :description="$description ?? null">
    {{ $slot }}
</x-ui::layouts.auth>
