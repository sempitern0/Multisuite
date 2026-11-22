<?php

namespace App\Events;

/**
 * A person no longer lets the assistant read their files. Dispatched inside the transaction
 * that removes the consent; SemanticIndex (owner of the index) forgets what it prepared.
 */
final class AssistantContentConsentWithdrawn
{
    public function __construct(public readonly int $userId) {}
}
