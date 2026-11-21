<?php

namespace App\Services\Assistant;

/**
 * Marks the moment a change tool runs because the person approved it in the chat
 * (ApprovalRequiredTool). Change tools refuse to act outside it, so a client that calls
 * them some other way (e.g. the MCP server) can never skip the approval (docs/MCP.md, MCP-04).
 */
final class ApprovedChange
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function run(callable $change): mixed
    {
        self::$depth++;
        try {
            return $change();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
