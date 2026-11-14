<?php

namespace App\Support;

use App\Models\ServiceAccount;
use App\Models\User;

/**
 * Presentation-neutral state of the services home page. Decides which services a
 * person sees and whether they can open them; themes only render the result.
 * Opening is still authorised by ServiceController (a hidden tile is not a gate).
 */
class ServiceTiles
{
    /**
     * @return list<array{id: string, name: string, icon: string, status: 'ready'|'pending'|'soon', label: string, hint: ?string, href: ?string, username: ?string}>
     */
    public static function forUser(User $user): array
    {
        $accounts = ServiceAccount::where('user_id', $user->id)->get()->keyBy('service');
        $access = $user->service_access;
        $tiles = [];

        /** @var array<string, array<string, mixed>> $services */
        $services = config('portal.services', []);
        foreach ($services as $id => $service) {
            $managed = (bool) config('provisioning.'.$id.'.enabled');
            // Managed services outside the invitation are not shown at all.
            if ($access !== null && $managed && ! in_array($id, $access, true)) {
                continue;
            }
            $account = $accounts->get($id);
            $name = (string) ($service['name'] ?? $id);
            $tile = ['id' => (string) $id, 'name' => $name, 'icon' => (string) ($service['icon'] ?? 'grid'), 'href' => null, 'username' => $account?->username];

            if ($access !== null && $managed && $account?->status !== 'ready') {
                $tiles[] = $tile + ['status' => 'pending', 'label' => 'Cuenta pendiente de preparación', 'hint' => match ($account?->status) {
                    'expired' => 'Vuelve a guardar tu contraseña en Ajustes para reintentarlo.',
                    'pending', null => 'Se está preparando. Vuelve en unos minutos.',
                    default => 'Avisa al administrador para activar el acceso.',
                }];
            } elseif (! empty($service['enabled']) && filled($service['url'] ?? null)) {
                $tiles[] = ['href' => route('services.open', $id)] + $tile + ['status' => 'ready', 'label' => 'Disponible', 'hint' => null];
            } else {
                $tiles[] = $tile + ['status' => 'soon', 'label' => 'Próximamente', 'hint' => null];
            }
        }

        return $tiles;
    }
}
