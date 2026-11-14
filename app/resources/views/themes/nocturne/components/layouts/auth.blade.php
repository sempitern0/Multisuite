@props(['title' => null, 'eyebrow' => null, 'heading' => null, 'description' => null])
{{-- Authentication layout: brand panel + one focused card per step (login, confirm, reset...). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="antialiased">
        <main class="ui-auth">
            <section class="ui-auth-visual" aria-hidden="true">
                <div>
                    <p class="ui-eyebrow">Multisuite</p>
                    <strong style="margin-top:24px">Todo tuyo.<br>Todo en orden.<br><span style="color:var(--ui-accent)">Todo en uno.</span></strong>
                    <p>Un lugar tranquilo para lo que de verdad importa.</p>
                </div>
                <span class="ui-auth-emblem"><x-ui::icon name="grid" /></span>
            </section>
            <section class="ui-auth-card">
                <a href="{{ route('home') }}" class="ui-auth-logo" aria-label="Multisuite"><x-ui::icon name="diamond" /></a>
                @if ($eyebrow)<p class="ui-eyebrow">{{ $eyebrow }}</p>@endif
                <h1 class="ui-auth-title">{{ $heading ?? $title }}</h1>
                @if ($description)<p class="ui-sub">{{ $description }}</p>@endif
                {{ $slot }}
                <p class="ui-auth-foot">Multisuite · Tu espacio personal</p>
            </section>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
