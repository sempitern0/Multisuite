<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use RuntimeException;

/** The person's ready account on a service, or a clear refusal (MCP-06). */
trait SearchesServiceCatalog
{
    protected function readyAccount(User $user, string $service, string $serviceName): ServiceAccount
    {
        $account = ServiceAccount::where('user_id', $user->id)->where('service', $service)->first();
        if (! (bool) config('portal.services.'.$service.'.enabled') || ! app(ServiceAccounts::class)->entitled($user, $service)) {
            throw new RuntimeException('No tienes '.$serviceName.' en Multisuite.');
        }
        if ($account === null || $account->status !== 'ready') {
            throw new RuntimeException('Tu cuenta de '.$serviceName.' aún no está lista. Ábrela una vez desde la portada de Multisuite.');
        }

        return $account;
    }

    protected static function serviceError(RuntimeException $exception, string $serviceName): string
    {
        return in_array($exception->getMessage(), ['unavailable', 'configuration', 'conflict', 'expired'], true)
            ? $serviceName.' no responde ahora mismo. Prueba más tarde.'
            : $exception->getMessage();
    }
}
