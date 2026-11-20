<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base of every assistant tool. The person is always the authenticated user of the request
 * (session or token), never an argument. Access, the tool switch and the daily budget are
 * checked in handle() too, because the chat (laravel/ai) calls handle() directly and skips
 * shouldRegister(). Tools only call domain services, which apply their own ownership rules.
 */
abstract class AssistantTool extends Tool
{
    abstract protected function run(User $user, Request $request): Response|ResponseFactory;

    public function shouldRegister(Request $request, AssistantAccess $access): bool
    {
        $user = $this->actor($request);

        return $user !== null && $access->denial($user) === null && $access->toolAvailable($user, $this->name());
    }

    public function handle(Request $request, AssistantAccess $access): Response|ResponseFactory
    {
        $user = $this->actor($request);
        $denial = $access->denial($user) ?? ($user !== null && in_array($this->name(), AssistantAccess::CONTENT_TOOLS, true)
            ? $access->contentDenial($user)
            : ($access->toolEnabled($this->name()) ? null : 'tool'));
        if ($user === null || $denial !== null) {
            if ($user !== null) {
                $access->record($user, $this->name(), 'denied');
            }

            return Response::error(AssistantAccess::DENIALS[$denial ?? 'guest']);
        }
        $call = $access->begin($user, $this->name());
        if ($call === null) {
            return Response::error(AssistantAccess::DENIALS['budget']);
        }

        $startedAt = hrtime(true);
        try {
            $response = $this->run($user, $request);
        } catch (ValidationException $exception) {
            $response = Response::error(implode(' ', Arr::flatten($exception->errors())));
        } catch (ModelNotFoundException) {
            $response = Response::error('No existe o no es tuyo.');
        } catch (Throwable $exception) {
            report($exception);
            $response = Response::error('No se pudo completar la consulta. Inténtalo de nuevo más tarde.');
        }
        $access->finish($call, $this->failed($response), $startedAt);

        return $response;
    }

    /** A fresh copy, so a suspension or role change since the session started applies now. */
    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? User::find($user->id) : null;
    }

    private function failed(Response|ResponseFactory $response): bool
    {
        return $response instanceof Response
            ? $response->isError()
            : $response->responses()->contains(fn (Response $item): bool => $item->isError());
    }
}
