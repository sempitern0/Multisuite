<?php

use App\Http\Middleware\EnsureExternalAssistant;
use App\Mcp\Servers\MultisuiteServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
 * External MCP clients (docs/MCP.md, MCP-05). OAuth 2.1 with PKCE through Passport; the token
 * only carries the mcp:use scope and every request re-checks the person, the assistant and the
 * owner's switch. Must only be reachable through the private network (P03).
 */
Mcp::oauthRoutes();

Mcp::web('/mcp', MultisuiteServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), EnsureExternalAssistant::class, 'throttle:assistant-mcp']);
