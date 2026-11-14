{{-- Indirection point: every auth step uses x-layouts::auth; the active theme draws it. --}}
<x-ui::layouts.auth :title="$title ?? null" :eyebrow="$eyebrow ?? null" :heading="$heading ?? null" :description="$description ?? null" :home-href="route('home')">
    {{ $slot }}
    @isset($links)
        <x-slot:links>{{ $links }}</x-slot:links>
    @endisset
</x-ui::layouts.auth>
