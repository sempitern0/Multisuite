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
     * @var list<array{group: ?string, items: list<array{route: string, label: string, icon: string, gate: ?string, active: list<string>}>}>
     */
    private const GROUPS = [
        ['group' => null, 'items' => [
            ['route' => 'dashboard', 'label' => 'Servicios', 'icon' => 'grid', 'gate' => null, 'active' => ['dashboard']],
            ['route' => 'files.index', 'label' => 'Mis archivos', 'icon' => 'folder', 'gate' => null, 'active' => ['files.*']],
        ]],
        ['group' => 'Administración', 'items' => [
            ['route' => 'users.index', 'label' => 'Personas', 'icon' => 'users', 'gate' => 'manage-invitations', 'active' => ['users.*']],
            ['route' => 'invitations.index', 'label' => 'Invitaciones', 'icon' => 'mail', 'gate' => 'manage-invitations', 'active' => ['invitations.*']],
            ['route' => 'storage.index', 'label' => 'Almacenamiento', 'icon' => 'database', 'gate' => 'view-storage', 'active' => ['storage.*']],
            ['route' => 'audit.index', 'label' => 'Actividad', 'icon' => 'clock', 'gate' => 'view-admin-audit', 'active' => ['audit.*']],
        ]],
    ];

    /** @var array{route: string, label: string, icon: string, active: list<string>} */
    private const SETTINGS = ['route' => 'profile.edit', 'label' => 'Ajustes', 'icon' => 'settings', 'active' => ['profile.*', 'security.*', 'appearance.*', 'settings.*']];

    /**
     * @return array{
     *     groups: list<array{label: ?string, items: list<array{label: string, icon: string, href: string, active: bool}>}>,
     *     account: array{name: string, initials: string, role: string, settings: array{label: string, icon: string, href: string, active: bool}, logout: string}
     * }
     */
    public static function for(User $user): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if ($item['gate'] !== null && Gate::forUser($user)->denies($item['gate'])) {
                    continue;
                }
                $items[] = self::link($item);
            }
            if ($items !== []) {
                $groups[] = ['label' => $group['group'], 'items' => $items];
            }
        }

        return [
            'groups' => $groups,
            'account' => [
                'name' => $user->name,
                'initials' => $user->initials(),
                'role' => $user->roleLabel(),
                'settings' => self::link(self::SETTINGS),
                'logout' => route('logout'),
            ],
        ];
    }

    /**
     * @param  array{route: string, label: string, icon: string, active: list<string>}  $item
     * @return array{label: string, icon: string, href: string, active: bool}
     */
    private static function link(array $item): array
    {
        return [
            'label' => $item['label'],
            'icon' => $item['icon'],
            'href' => route($item['route']),
            'active' => request()->routeIs(...$item['active']),
        ];
    }
}
