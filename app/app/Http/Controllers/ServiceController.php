<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use App\Services\MusicSessionBridge;
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

        if ($service === 'music' && config('portal.music_session_bridge')
            && config('provisioning.music.enabled') && $user?->service_access !== null) {
            $account = ServiceAccount::where('user_id', $user->id)->where('service', 'music')->where('status', 'ready')->firstOrFail();
            abort_if(app()->isProduction() && $parts['scheme'] !== 'https', 404);
            abort_if(str_starts_with($parts['path'] ?? '', '/_multisuite/'), 404);
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $ticket = app(MusicSessionBridge::class)->issue($account, $url);

            return redirect()->away($origin.'/_multisuite/session#ticket='.rawurlencode($ticket))
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->away($url);
    }
}
