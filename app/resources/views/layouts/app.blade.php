{{-- Indirection point: every page uses x-layouts::app; the active theme draws it with data computed here. --}}
<x-ui::layouts.app :title="$title ?? null" :navigation="\App\Support\Navigation::for(auth()->user())" :html-attributes="auth()->user()->preferences()->htmlAttributes()">
    {{ $slot }}
</x-ui::layouts.app>
