@props(['title' => null, 'navigation', 'htmlAttributes' => []])
{{--
    Application shell. Draws App\Support\Navigation::for(): groups already filtered by
    gates and the account block. No permission or route logic lives in the theme.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" @foreach ($htmlAttributes as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach>
    <head>
        @include('partials.head')
    </head>
    <body class="antialiased" x-data x-on:ui-preferences.window="Object.entries($event.detail.attributes).forEach(([name, value]) => document.documentElement.setAttribute(name, value))">
        <a href="#contenido" class="ui-skip">Saltar al contenido</a>
        <div class="ui-shell" data-layout="app" x-data="{ navOpen: false }" x-bind:class="navOpen && 'ui-nav-open'" x-on:keydown.escape.window="navOpen = false">
            <aside class="ui-sidebar" aria-label="Navegación principal">
                <a href="{{ $navigation['home'] }}" wire:navigate class="ui-brand">
                    <span class="ui-brandmark"><x-ui::icon name="diamond" /></span>
                    <span class="ui-hide-compact">
                        <span class="ui-brandtext">Multisuite</span>
                        <span class="ui-brandsub">Tu espacio</span>
                    </span>
                </a>
                <nav class="ui-sidebar-main">
                    @foreach ($navigation['groups'] as $group)
                        @if ($group['label'])<p class="ui-nav-title ui-hide-compact">{{ $group['label'] }}</p>@endif
                        @foreach ($group['items'] as $item)
                            <x-ui::nav-item :href="$item['href']" :icon="$item['icon']" :active="$item['active']" :spa="$item['spa']">{{ $item['label'] }}</x-ui::nav-item>
                        @endforeach
                    @endforeach
                </nav>
                @php($account = $navigation['account'])
                <div class="ui-sidebar-bottom">
                    <div class="ui-person">
                        <span class="ui-avatar" aria-hidden="true">{{ $account['initials'] }}</span>
                        <span class="ui-hide-compact ui-person-text">
                            <span class="ui-person-name">{{ $account['name'] }}</span>
                            <span class="ui-muted">{{ $account['role'] }}</span>
                        </span>
                    </div>
                    <x-ui::nav-item :href="$account['settings']['href']" :icon="$account['settings']['icon']" :active="$account['settings']['active']" :spa="$account['settings']['spa']">{{ $account['settings']['label'] }}</x-ui::nav-item>
                    <form method="POST" action="{{ $account['logout'] }}">
                        @csrf
                        <button type="submit" class="ui-nav-item" data-test="logout-button">
                            <x-ui::icon name="logout" /><span class="ui-hide-compact">Cerrar sesión</span>
                        </button>
                    </form>
                </div>
            </aside>
            <div class="ui-shade" x-on:click="navOpen = false" aria-hidden="true"></div>
            <div class="ui-main">
                <header class="ui-topbar">
                    <button type="button" class="ui-iconbtn ui-menubtn" x-on:click="navOpen = true" aria-label="Abrir el menú"><x-ui::icon name="menu" /></button>
                    <p class="ui-topbar-title">Multisuite @if (filled($title)) <span aria-hidden="true">/</span> <strong>{{ $title }}</strong>@endif</p>
                </header>
                <main id="contenido" class="ui-content" tabindex="-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('ui-toasts')
            <x-ui::toasts />
        @endpersist

        @fluxScripts
    </body>
</html>
