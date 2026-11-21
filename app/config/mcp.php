<?php

/*
 * laravel/mcp (docs/MCP.md, MCP-05). External MCP clients register themselves (OAuth dynamic
 * client registration) but may only send people back to these addresses. Nothing here is a
 * secret; the endpoint stays off until the owner allows external clients.
 */
return [
    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai/,https://claude.com/,https://chatgpt.com/,http://localhost,http://127.0.0.1',
    ))))),

    'custom_schemes' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_CUSTOM_SCHEMES', ''))))),

    'allowed_origins' => [],

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],
];
