<?php

namespace App\Http\Controllers;

use App\Models\UserStorageQuota;
use App\Support\AdminSummary;
use App\Support\ServiceTiles;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $storage = UserStorageQuota::summaryFor($user->id);

        return view('dashboard', [
            'tiles' => ServiceTiles::forUser($user),
            'storage' => $storage['limit'] > 0 ? $storage : null,
            'admin' => AdminSummary::for($user),
        ]);
    }
}
