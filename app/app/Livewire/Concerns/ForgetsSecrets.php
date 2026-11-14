<?php

namespace App\Livewire\Concerns;

/**
 * Livewire sends public properties back to the browser after every request. Anything
 * typed as a secret (passwords) is cleared when the operation ends, whether it
 * succeeded, failed validation or was refused.
 */
trait ForgetsSecrets
{
    /**
     * @template T
     *
     * @param  list<string>  $properties
     * @param  callable(): T  $operation
     * @return T
     */
    protected function forgetting(array $properties, callable $operation): mixed
    {
        try {
            return $operation();
        } finally {
            $this->reset(...$properties);
        }
    }
}
