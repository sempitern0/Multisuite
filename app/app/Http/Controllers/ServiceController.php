<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ServiceController extends Controller
{
    public function __invoke(string $service): RedirectResponse
    {
        $entry = config('portal.services', [])[$service] ?? null;
        abort_unless(is_array($entry) && ($entry['enabled'] ?? false), 404);

        $user = Auth::user();
        if ($user !== null && $user->service_access !== null && config('provisioning.'.$service.'.enabled')) {
            abort_unless(in_array($service, $user->service_access, true), 403);
            if (! ServiceAccount::where('user_id', $user->id)->where('service', $service)->where('status', 'ready')->exists()) {
                return redirect()->route('dashboard');
            }
        }

        $url = $entry['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! preg_match('/[\x00-\x20\x7f]/', $url), 404);

        return redirect()->away($url);
    }
}
