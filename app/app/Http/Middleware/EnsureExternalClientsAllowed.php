<?php

namespace App\Http\Middleware;

use App\Models\AssistantSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic client registration (POST /oauth/register) is open by design in OAuth 2.1, so it is
 * only accepted while the owner allows external MCP clients; otherwise nobody can create one.
 */
class EnsureExternalClientsAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AssistantSetting::current()->external_enabled) {
            return response()->json(['error' => 'access_denied', 'error_description' => 'Multisuite no permite clientes MCP externos.'], 403);
        }

        return $next($request);
    }
}
