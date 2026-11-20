<?php

namespace App\Ai\Tools;

use App\Services\Assistant\ApprovedChange;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;

/**
 * A change tool offered to the chat: laravel/ai pauses before running it and the person
 * approves or rejects it in the portal. handle() only runs after an approval, so it opens
 * the ApprovedChange window that the tool itself requires.
 */
class ApprovalRequiredTool extends McpServerTool
{
    public function handle(Request $request): string
    {
        return ApprovedChange::run(fn (): string => parent::handle($request));
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Este cambio en Mis archivos necesita tu aprobación.');
    }
}
