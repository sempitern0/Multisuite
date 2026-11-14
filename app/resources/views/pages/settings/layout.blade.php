@props(['heading' => '', 'subheading' => ''])
<div>
    <x-ui::page-header eyebrow="Ajustes" :title="$heading" :description="$subheading" />
    <x-ui::stack gap="lg">
        <x-ui::subnav label="Ajustes" :items="[
            ['label' => 'Perfil', 'href' => route('profile.edit'), 'active' => request()->routeIs('profile.edit')],
            ['label' => 'Seguridad', 'href' => route('security.edit'), 'active' => request()->routeIs('security.edit')],
            ['label' => 'Preferencias', 'href' => route('preferences.edit'), 'active' => request()->routeIs('preferences.edit')],
        ]" />
        <x-ui::container>{{ $slot }}</x-ui::container>
    </x-ui::stack>
</div>
