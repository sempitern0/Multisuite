{{-- Indirection point: every page uses x-layouts::app; the active theme draws it with data computed here. --}}
@php
    // Floating assistant: only for people who can use it, if they keep the button, never on the Asistente page.
    $assistantDock = auth()->user()->preferences()->assistantButton() && ! request()->routeIs('assistant.index')
        && \Illuminate\Support\Facades\Gate::allows('use-assistant');
@endphp
<x-ui::layouts.app :title="$title ?? null" :navigation="\App\Support\Navigation::for(auth()->user())" :html-attributes="auth()->user()->preferences()->htmlAttributes()" :flash="session('toast')">
    {{ $slot }}
    @if ($assistantDock)
        <x-slot:dock><livewire:pages::assistant-dock /></x-slot:dock>
    @endif
</x-ui::layouts.app>
