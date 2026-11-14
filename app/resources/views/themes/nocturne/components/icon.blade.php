@props(['name'])
{{-- Uniform 24px stroke icons drawn for Multisuite (no external requests). --}}
@php
    $paths = [
        'music' => '<path d="M9 18V6l11-2v12"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
        'folder' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H9l2 2.5h7.5A2.5 2.5 0 0 1 21 10v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/>',
        'document' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'package' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'notes' => '<rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'video' => '<rect x="3" y="5" width="13" height="14" rx="2.5"/><path d="m16 10 5-3v10l-5-3"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
        'grid' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'database' => '<ellipse cx="12" cy="5.5" rx="8" ry="2.5"/><path d="M4 5.5v13c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-13M4 12c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2.5 12h3M18.5 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8.5 7 10 4-1.5 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'eye' => '<path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18M10.6 5.1A9.7 9.7 0 0 1 12 5c6 0 9.5 7 9.5 7a16 16 0 0 1-2.9 3.8M6.6 6.6A16.5 16.5 0 0 0 2.5 12S6 19 12 19a9 9 0 0 0 4.4-1.1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'alert' => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.5v.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a2 2 0 0 0 2 1.5h6a2 2 0 0 0 2-1.5L18 7M9 7V4.5h6V7"/>',
        'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>',
        'upload' => '<path d="M12 20V9M7 13.5l5-5 5 5M5 4h14"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'diamond' => '<path d="M12 2.5 21.5 12 12 21.5 2.5 12z"/><path d="M12 7.5 16.5 12 12 16.5 7.5 12z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
@endphp
<svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true', 'focusable' => 'false']) }}>{!! $paths[$name] ?? $paths['document'] !!}</svg>
