<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Typography belongs to the active theme (system stack in Nocturne): no web fonts. --}}
@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- The active theme is dark-only: no light/dark switcher (@fluxAppearance) on purpose. --}}
