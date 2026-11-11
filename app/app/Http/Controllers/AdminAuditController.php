<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditEvent;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminAuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('view-admin-audit');
        $labels = AdminAudit::actionLabels();
        $data = $request->validate([
            'action' => ['nullable', Rule::in(array_keys($labels))],
            'service' => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,64}$/D'],
        ]);
        $action = $data['action'] ?? '';
        $service = $data['service'] ?? '';
        $services = AdminAuditEvent::whereNotNull('service_id')->distinct()->orderBy('service_id')->pluck('service_id');
        $events = AdminAuditEvent::query()->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($service !== '', fn ($query) => $query->where('service_id', $service))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('audit.index', ['events' => $events, 'action' => $action, 'labels' => $labels, 'service' => $service, 'services' => $services]);
    }
}
