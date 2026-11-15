<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\UserPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Remembers the collapse button of the side menu (resources/js/sidebar.js). */
class SidebarPreferenceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate(['sidebar' => ['required', Rule::in(UserPreferences::OPTIONS['sidebar'])]]);
        $user = $request->user();
        $user->preferences = $user->preferences()->with('sidebar', $data['sidebar'])->toArray();
        $user->save();

        return response()->noContent();
    }
}
