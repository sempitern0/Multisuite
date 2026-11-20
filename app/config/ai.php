<?php

/*
 * laravel/ai (docs/MCP.md). Providers are not configured here: the owner adds them in
 * Administración › Asistente and they are built per conversation (decision D2).
 */
return [
    'default' => null,

    'providers' => [],

    'conversations' => [
        // Titles are the first words of the question: no extra request to the provider.
        'generate_title' => false,
        'tables' => [
            'conversations' => 'agent_conversations',
            'messages' => 'agent_conversation_messages',
        ],
    ],
];
