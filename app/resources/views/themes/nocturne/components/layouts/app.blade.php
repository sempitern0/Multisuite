@props(['title' => null])
{{--
    Application shell. Navigation order (design guide): main area first, administration
    only when the gates allow it, account fixed at the bottom. Hiding a link never
    protects a route: every admin route keeps its own middleware/policies.
--}}
@php
    $user = auth()->user();
    $is = fn (string ...$patterns): bool => request()->routeIs(...$patterns);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="antialiased">
        <div class="ui-shell" x-data="{ navOpen: false }" x-bind:class="navOpen && 'ui-nav-open'" x-on:keydown.escape.window="navOpen = false">
            <aside class="ui-sidebar" aria-label="Navegación principal">
                <a href="{{ route('dashboard') }}" class="ui-brand">
                    <span class="ui-brandmark"><x-ui::icon name="diamond" /></span>
                    <span class="ui-hide-compact">
                        <span class="ui-brandtext" style="display:block">Multisuite</span>
                        <span class="ui-brandsub">Tu espacio</span>
                    </span>
                </a>
                <nav class="ui-sidebar-main">
                    <x-ui::nav-item :href="route('dashboard')" icon="grid" :active="$is('dashboard')">Servicios</x-ui::nav-item>
                    @if (Route::has('files.index'))
                        <x-ui::nav-item :href="route('files.index')" icon="folder" :active="$is('files.*')">Mis archivos</x-ui::nav-item>
                    @endif
                    @canany(['manage-invitations', 'view-storage', 'view-admin-audit'])
                        <p class="ui-nav-title ui-hide-compact">Administración</p>
                        @can('manage-invitations')
                            <x-ui::nav-item :href="route('users.index')" icon="users" :active="$is('users.*')">Personas</x-ui::nav-item>
                            <x-ui::nav-item :href="route('invitations.index')" icon="mail" :active="$is('invitations.*')">Invitaciones</x-ui::nav-item>
                        @endcan
                        @can('view-storage')
                            <x-ui::nav-item :href="route('storage.index')" icon="database" :active="$is('storage.*')">Almacenamiento</x-ui::nav-item>
                        @endcan
                        @can('view-admin-audit')
                            <x-ui::nav-item :href="route('audit.index')" icon="clock" :active="$is('audit.*')">Actividad</x-ui::nav-item>
                        @endcan
                    @endcanany
                </nav>
                <div class="ui-sidebar-bottom">
                    <div class="ui-person">
                        <span class="ui-avatar" aria-hidden="true">{{ $user?->initials() }}</span>
                        <span class="ui-hide-compact" style="min-width:0">
                            <span style="display:block;font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $user?->name }}</span>
                            <span class="ui-muted">{{ ['owner' => 'Propietario', 'admin' => 'Administrador', 'member' => 'Miembro'][$user?->role] ?? '' }}</span>
                        </span>
                    </div>
                    <x-ui::nav-item :href="route('profile.edit')" icon="settings" :active="$is('profile.*', 'security.*', 'appearance.*', 'settings.*')">Ajustes</x-ui::nav-item>
                    <form method="POST" action="{{ route('logout') }}">
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
                <main class="ui-content">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
