<?php

namespace App\Http\Middleware;

use App\Models\AssistantSetting;
use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every external MCP request (MCP-05): the owner allows external clients, and the person behind
 * the token may use the assistant right now (verified, not suspended, initial password changed,
 * access granted). A token issued earlier stops working as soon as any of this changes.
 */
class EnsureExternalAssistant
{
    public function __construct(private AssistantAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $fresh = $user instanceof User ? User::find($user->id) : null;
        $denial = AssistantSetting::current()->external_enabled ? $this->access->denial($fresh) : 'external_off';
        if ($denial !== null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => $denial === 'external_off'
                    ? 'Multisuite no permite clientes MCP externos.'
                    : AssistantAccess::DENIALS[$denial]],
            ], 403);
        }

        return $next($request);
    }
}
