<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use App\Services\KomgaAccounts;
use App\Services\KomgaSession;
use App\Services\MusicSessionBridge;
use App\Services\ReadingSession;
use App\Services\ServiceAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ServiceController extends Controller
{
    public function __invoke(string $service): RedirectResponse|Response
    {
        $entry = config('portal.services', [])[$service] ?? null;
        abort_unless(is_array($entry) && ($entry['enabled'] ?? false), 404);

        $user = Auth::user();
        abort_if(($entry['admin_only'] ?? false) && ! in_array($user?->role, ['owner', 'admin'], true), 403);
        if ($service === 'komga' && $user !== null) {
            abort_unless(app(ServiceAccounts::class)->entitled($user, 'komga'), 403);
            if (! KomgaAccounts::connected()) {
                return redirect()->route($user->role === 'owner' ? 'komga.connect' : 'komga.confirm');
            }
            if (! KomgaSession::allowed($user) || ! session()->has('komga.login')) {
                return redirect()->route('komga.confirm');
            }
        }
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
            && isset($parts['host'])
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! preg_match('/[\x00-\x20\x7f]/', $url), 404);

        if ($service === 'komga' && $user !== null) {
            abort_if(app()->isProduction() && $parts['scheme'] !== 'https', 404);
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

            return response()->view('komga-launch', [
                'action' => $origin.'/_multisuite/launch',
                'ticket' => app(KomgaSession::class)->issue($user),
            ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'origin');
        }

        if ($service === 'reading' && config('portal.reading_autologin') && config('provisioning.reading.enabled')
            && $user !== null && $user->service_access !== null) {
            abort_if(app()->isProduction() && $parts['scheme'] !== 'https', 404);
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

            return response()->view('reading-launch', ['action' => $origin.'/_multisuite/launch', 'ticket' => app(ReadingSession::class)->issue($user)])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'origin');
        }

        if ($service === 'music' && config('portal.music_session_bridge')
            && config('provisioning.music.enabled') && $user?->service_access !== null) {
            $account = ServiceAccount::where('user_id', $user->id)->where('service', 'music')->where('status', 'ready')->firstOrFail();
            abort_if(app()->isProduction() && $parts['scheme'] !== 'https', 404);
            abort_if(str_starts_with($parts['path'] ?? '', '/_multisuite/'), 404);
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $challenge = request()->query('music_challenge');
            abort_unless($challenge === null || (is_string($challenge) && preg_match('/^[a-f0-9]{64}$/D', $challenge)), 400);
            $ticket = app(MusicSessionBridge::class)->issue($account, $url, $challenge);

            return redirect()->away($origin.'/_multisuite/session#ticket='.rawurlencode($ticket))
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->away($url);
    }
}
