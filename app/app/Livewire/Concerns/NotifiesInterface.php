<?php

namespace App\Livewire\Concerns;

/**
 * Browser events the active theme listens to. Components describe what happened;
 * the theme decides how it looks (toast, attributes on <html>).
 */
trait NotifiesInterface
{
    /** Short confirmation shown only after the server has applied the change. */
    protected function toast(string $text, string $tone = 'ok'): void
    {
        $this->dispatch('ui-toast', text: $text, tone: $tone);
    }

    /** @param array<string, string> $attributes */
    protected function applyHtmlAttributes(array $attributes): void
    {
        $this->dispatch('ui-preferences', attributes: $attributes);
    }
}
