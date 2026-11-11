<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminAuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('view-admin-audit');
        $data = $request->validate(['action' => ['nullable', Rule::in(['user.created', 'user.updated', 'user.deleted'])]]);
        $action = $data['action'] ?? '';
        $events = AdminAuditEvent::query()->when($action !== '', fn ($query) => $query->where('action', $action))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('audit.index', ['events' => $events, 'action' => $action]);
    }
}
