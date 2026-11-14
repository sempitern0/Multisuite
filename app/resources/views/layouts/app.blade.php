{{-- Indirection point: every page uses x-layouts::app; the active theme draws it. --}}
<x-ui::layouts.app :title="$title ?? null">
    {{ $slot }}
</x-ui::layouts.app>
