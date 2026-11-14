@props(['paginator'])
{{-- Contract: Livewire pagination (WithPagination); changes page without reloading. --}}
@php($pageName = $paginator->getPageName())
<nav class="ui-pager" aria-label="Paginación">
    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" wire:click="previousPage('{{ $pageName }}')" @disabled($paginator->onFirstPage())>Anterior</button>
    <span class="ui-pager-status" aria-live="polite">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" wire:click="nextPage('{{ $pageName }}')" @disabled(! $paginator->hasMorePages())>Siguiente</button>
</nav>
