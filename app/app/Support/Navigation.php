<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Application navigation, already filtered by the person's gates. Themes only
 * draw the result, so adding a section never means editing every theme.
 * Hiding an entry is not authorisation: each route keeps its own middleware.
 */
class Navigation
{
    /**
     * Each entry: route name, label, theme icon, gate (null = every signed-in person)
     * and the route patterns that mark it as the current section.
     *
     * @var list<array{group: ?string, items: list<array{route: string, label: string, icon: string, gate: ?string, active: list<string>, spa?: bool}>}>
     */
    private const GROUPS = [
        ['group' => null, 'items' => [
            ['route' => 'dashboard', 'label' => 'Servicios', 'icon' => 'grid', 'gate' => null, 'active' => ['dashboard']],
            ['route' => 'files.index', 'label' => 'Mis archivos', 'icon' => 'folder', 'gate' => null, 'active' => ['files.*']],
            ['route' => 'finances.index', 'label' => 'Mis finanzas', 'icon' => 'wallet', 'gate' => 'use-finances', 'active' => ['finances.*']],
            ['route' => 'assistant.index', 'label' => 'Asistente', 'icon' => 'diamond', 'gate' => 'use-assistant', 'active' => ['assistant.index']],
        ]],
        ['group' => 'Administración', 'items' => [
            ['route' => 'komga.connect', 'label' => 'Conexión de Lectura', 'icon' => 'book', 'gate' => 'manage-storage-pools', 'active' => ['komga.connect']],
            ['route' => 'video.connect', 'label' => 'Conexión de Vídeo', 'icon' => 'video', 'gate' => 'manage-storage-pools', 'active' => ['video.connect']],
            ['route' => 'users.index', 'label' => 'Personas', 'icon' => 'users', 'gate' => 'manage-invitations', 'active' => ['users.*']],
            ['route' => 'invitations.index', 'label' => 'Invitaciones', 'icon' => 'invite', 'gate' => 'manage-invitations', 'active' => ['invitations.*']],
            ['route' => 'publications.index', 'label' => 'Para todos', 'icon' => 'share', 'gate' => 'publish-content', 'active' => ['publications.*']],
            ['route' => 'downloads.index', 'label' => 'Descargas por guardar', 'icon' => 'download', 'gate' => 'manage-downloads', 'active' => ['downloads.*']],
            ['route' => 'storage.index', 'label' => 'Almacenamiento', 'icon' => 'storage', 'gate' => 'view-storage', 'active' => ['storage.*']],
            ['route' => 'assistant.settings', 'label' => 'Ajustes del asistente', 'icon' => 'settings', 'gate' => 'manage-assistant', 'active' => ['assistant.settings']],
            ['route' => 'audit.index', 'label' => 'Actividad', 'icon' => 'activity', 'gate' => 'view-admin-audit', 'active' => ['audit.*']],
        ]],
    ];

    /** @var array{route: string, label: string, icon: string, active: list<string>} */
    private const SETTINGS = ['route' => 'profile.edit', 'label' => 'Ajustes', 'icon' => 'settings', 'active' => ['profile.*', 'security.*', 'preferences.*', 'settings.*']];

    /**
     * @return array{
     *     home: string,
     *     groups: list<array{label: ?string, items: list<array{label: string, icon: string, href: string, active: bool, spa: bool}>}>,
     *     account: array{name: string, initials: string, avatar: ?string, role: string, settings: array{label: string, icon: string, href: string, active: bool, spa: bool}, logout: string},
     *     sidebar: array{compact: bool, save: string}
     * }
     */
    public static function for(User $user): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if ($item['route'] === 'komga.connect' && ! config('portal.services.komga.enabled')) {
                    continue;
                }
                if ($item['route'] === 'video.connect' && ! (config('portal.services.video.enabled') && config('provisioning.video.enabled'))) {
                    continue;
                }
                if ($item['gate'] !== null && Gate::forUser($user)->denies($item['gate'])) {
                    continue;
                }
                $items[] = self::link($item);
            }
            if ($items !== []) {
                $groups[] = ['label' => $group['group'], 'items' => $items];
            }
        }

        $services = [];
        foreach (ServiceTiles::forUser($user) as $tile) {
            if ($tile['href'] === null) {
                continue;
            }
            $services[] = [
                'label' => $tile['name'], 'icon' => $tile['icon'], 'href' => $tile['href'],
                'active' => false, 'spa' => false,
            ];
        }
        if ($services !== []) {
            array_splice($groups, 1, 0, [['label' => 'Aplicaciones', 'items' => $services]]);
        }

        return [
            'home' => route('dashboard'),
            'groups' => $groups,
            'account' => [
                'name' => $user->name,
                'initials' => $user->initials(),
                'avatar' => $user->avatarUrl(),
                'role' => $user->roleLabel(),
                'roleKey' => $user->role,
                'roleLevel' => $user->roleLevel(),
                'settings' => self::link(self::SETTINGS),
                'logout' => route('logout'),
            ],
            'sidebar' => ['compact' => $user->preferences()->sidebar() === 'compact', 'save' => route('preferences.sidebar')],
        ];
    }

    /**
     * @param  array{route: string, label: string, icon: string, active: list<string>, spa?: bool}  $item
     * @return array{label: string, icon: string, href: string, active: bool, spa: bool}
     */
    private static function link(array $item): array
    {
        return [
            'label' => $item['label'],
            'icon' => $item['icon'],
            'href' => route($item['route']),
            'active' => request()->routeIs(...$item['active']),
            'spa' => $item['spa'] ?? true,
        ];
    }
}
