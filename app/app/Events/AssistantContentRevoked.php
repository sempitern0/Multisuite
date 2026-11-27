<?php

namespace App\Events;

/**
 * The assistant may no longer read some of a person's files: they withdrew their consent or
 * excluded a folder. Dispatched inside the transaction that records it; SemanticIndex (owner of
 * the index) forgets what it prepared and the next pass rebuilds only what is still eligible.
 */
final class AssistantContentRevoked
{
    public function __construct(public readonly int $userId) {}
}
