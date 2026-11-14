<?php

namespace App\Http\Controllers;

use App\Actions\Storage\UpdateStorageLimits;
use App\Models\User;
use App\Support\Bytes;
use App\Support\SizeInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Form endpoints for storage limits; the page is Livewire (pages::admin.storage)
 * and both use UpdateStorageLimits. Paths, mounts and partitions stay in the
 * operator's server configuration.
 */
class StorageController extends Controller
{
    public function __construct(private UpdateStorageLimits $limits) {}

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-storage-quota', $user);
        $field = 'quota_'.$user->id;
        $bytes = SizeInput::bytes($request->only('preset', 'amount', 'unit'), $field);
        $this->limits->userQuota($request->user(), $user, $bytes, $field);

        return back()->with('toast', 'Espacio de '.$user->name.': '.Bytes::format($bytes).' como máximo');
    }

    public function updatePool(Request $request, string $pool): RedirectResponse
    {
        $field = 'pool_'.$pool;
        Gate::authorize('manage-storage-pools');
        $bytes = SizeInput::bytes($request->only('preset', 'amount', 'unit'), $field);
        $this->limits->poolLimit($request->user(), $pool, $bytes, $field);

        return back()->with('toast', 'Límite del disco '.$pool.': '.Bytes::format($bytes));
    }

    public function updateDefault(Request $request): RedirectResponse
    {
        Gate::authorize('manage-storage-pools');
        $bytes = SizeInput::bytes($request->only('preset', 'amount', 'unit'), 'default');
        $this->limits->defaultQuota($request->user(), $bytes, 'default');

        return back()->with('toast', 'Espacio por defecto para personas nuevas: '.Bytes::format($bytes));
    }
}
