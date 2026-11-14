<?php

namespace App\Http\Controllers;

use App\Models\StorageSetting;
use App\Models\UserStorageQuota;
use App\Support\ServiceTiles;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $quota = UserStorageQuota::find($user->id);
        $limit = (int) ($quota?->limit_bytes ?? StorageSetting::defaultUserQuotaBytes());

        return view('dashboard', [
            'tiles' => ServiceTiles::forUser($user),
            'storage' => $limit > 0 ? ['used' => (int) ($quota?->used_bytes ?? 0), 'limit' => $limit] : null,
        ]);
    }
}
