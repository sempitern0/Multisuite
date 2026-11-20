@props(['title' => null, 'navigation', 'htmlAttributes' => [], 'flash' => null])
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
        <div class="ui-shell" data-layout="app" x-data="{
            navOpen: false,
            openNav() {
                this.navOpen = true;
                this.$nextTick(() => requestAnimationFrame(() => this.$refs.closeMenu.focus()));
            },
            closeNav() {
                this.navOpen = false;
                this.$nextTick(() => this.$refs.menuTrigger.focus());
            },
            cycleNav(event) {
                if (!this.navOpen) return;
                const items = Array.from(this.$refs.sidebar.querySelectorAll('a[href], button:enabled')).filter(el => el.getClientRects().length);
                const first = items[0], last = items.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }" x-on:resize.window.debounce.100ms="if (innerWidth > 700) navOpen = false" x-bind:class="navOpen && 'ui-nav-open'" x-on:keydown.escape.window="if (navOpen) { closeNav() }">
            <aside x-ref="sidebar" x-on:keydown.tab="cycleNav($event)" id="portal-nav" class="ui-sidebar" aria-label="Navegación principal">
                <div class="ui-sidebar-head">
                    <a href="{{ $navigation['home'] }}" wire:navigate x-ref="navHome" class="ui-brand">
                        <span class="ui-brandmark"><x-ui::icon name="diamond" /></span>
                        <span class="ui-hide-compact">
                            <span class="ui-brandtext">Multisuite</span>
                            <span class="ui-brandsub">Tu espacio</span>
                        </span>
                    </a>
                    <button type="button" class="ui-iconbtn ui-menubtn" x-ref="closeMenu" x-on:click="closeNav()" aria-label="Cerrar el menú"><x-ui::icon name="close" /></button>
                    @php($compact = $navigation['sidebar']['compact'])
                    <button type="button" class="ui-collapse" data-sidebar-toggle data-save="{{ $navigation['sidebar']['save'] }}" data-csrf="{{ csrf_token() }}"
                        aria-label="Contraer o expandir el menú" aria-expanded="{{ $compact ? 'false' : 'true' }}" title="{{ $compact ? 'Expandir el menú' : 'Contraer el menú' }}">
                        <x-ui::icon name="chevrons-left" />
                    </button>
                </div>
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
                        <x-ui::avatar :src="$account['avatar']" :initials="$account['initials']" live />
                        <span class="ui-hide-compact ui-person-text">
                            <span class="ui-person-name">{{ $account['name'] }}</span>
                            <span class="ui-muted">{{ $account['role'] }}</span>
                        </span>
                    </div>
                    <x-ui::nav-item :href="$account['settings']['href']" :icon="$account['settings']['icon']" :active="$account['settings']['active']" :spa="$account['settings']['spa']">{{ $account['settings']['label'] }}</x-ui::nav-item>
                    <form method="POST" action="{{ $account['logout'] }}">
                        @csrf
                        <button type="submit" class="ui-nav-item" data-test="logout-button" title="Cerrar sesión">
                            <x-ui::icon name="logout" /><span class="ui-hide-compact">Cerrar sesión</span>
                        </button>
                    </form>
                </div>
            </aside>
            <div class="ui-shade" x-on:click="closeNav()" aria-hidden="true"></div>
            <div class="ui-main" x-bind:inert="navOpen">
                <header class="ui-topbar">
                    <button type="button" class="ui-iconbtn ui-menubtn" x-ref="menuTrigger" x-on:click="openNav()" aria-controls="portal-nav" x-bind:aria-expanded="navOpen.toString()" aria-label="Abrir el menú"><x-ui::icon name="menu" /></button>
                    <p class="ui-topbar-title">Multisuite @if (filled($title)) <span aria-hidden="true">/</span> <strong>{{ $title }}</strong>@endif</p>
                </header>
                <main id="contenido" class="ui-content" tabindex="-1">
                    <p wire:offline class="ui-notice ui-notice-warn" role="status">Sin conexión. Vuelve a conectarte al NAS para guardar cambios.</p>
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-ui::toasts :flash="$flash" />

        @fluxScripts
    </body>
</html>
