<?php

namespace App\Support;

use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Domain services refuse an operation with a RuntimeException whose message is
 * written for people (never paths). At the edge that becomes a validation message
 * next to the control that caused it; "not found" and HTTP errors pass through.
 */
final class DomainErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function guard(string $field, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof RecordsNotFoundException) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
