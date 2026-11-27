@props(['title' => null, 'eyebrow' => null, 'heading' => null, 'description' => null, 'homeHref' => '/'])
{{-- Authentication layout: brand panel + one focused card per step (login, confirm, reset...). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-motion="system" data-density="comfortable" data-sidebar="full">
    <head>
        @include('partials.head')
    </head>
    <body class="antialiased">
        <x-ui::scheme-toggle class="ui-auth-scheme" />
        <main class="ui-auth" data-layout="auth">
            <section class="ui-auth-card">
                <a href="{{ $homeHref }}" class="ui-auth-logo" aria-label="Multisuite"><x-ui::icon name="diamond" /></a>
                @if ($eyebrow)<p class="ui-eyebrow">{{ $eyebrow }}</p>@endif
                <h1 class="ui-auth-title">{{ $heading ?? $title }}</h1>
                @if ($description)<p class="ui-sub">{{ $description }}</p>@endif
                <div class="ui-auth-body">{{ $slot }}</div>
                @isset($links)<div class="ui-auth-links">{{ $links }}</div>@endisset
                <p class="ui-auth-foot">Multisuite · Tu espacio personal</p>
            </section>
        </main>

        <x-ui::toasts />

    </body>
</html>
