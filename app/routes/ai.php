<?php

use App\Http\Middleware\EnsureExternalAssistant;
use App\Http\Middleware\EnsureExternalClientsAllowed;
use App\Mcp\Servers\MultisuiteServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
 * External MCP clients (docs/MCP.md, MCP-05). OAuth 2.1 with PKCE through Passport; the token
 * only carries the mcp:use scope and every request re-checks the person, the assistant and the
 * owner's switch. Must only be reachable through the private network (P03).
 */
Route::middleware('throttle:mcp-oauth')->group(function (): void {
    Mcp::oauthRoutes(registration: false);
    // Registration is open by design in OAuth 2.1: throttled per address and only while the
    // owner allows external clients.
    Route::post('oauth/register', OAuthRegisterController::class)
        ->middleware(EnsureExternalClientsAllowed::class)->name('mcp.oauth.register');
});

Mcp::web('/mcp', MultisuiteServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), EnsureExternalAssistant::class, 'throttle:assistant-mcp']);
