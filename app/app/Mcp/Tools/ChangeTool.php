<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Assistant\ApprovedChange;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use RuntimeException;

/**
 * Base of the tools that change Mis archivos (MCP-04). They only act inside an approval given
 * by the person in the chat, call the same PersonalFiles operations as the pages and answer
 * success only once the change is saved. All changes are reversible.
 */
abstract class ChangeTool extends AssistantTool
{
    abstract protected function change(User $user, Request $request): ResponseFactory;

    protected function run(User $user, Request $request): Response|ResponseFactory
    {
        if (! ApprovedChange::active()) {
            return Response::error(AssistantAccess::DENIALS['approval']);
        }
        try {
            return $this->change($user, $request);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // Domain messages are written for people ("Ya existe una carpeta con ese nombre").
            return Response::error($exception->getMessage());
        }
    }
}
