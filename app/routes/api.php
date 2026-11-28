<?php

use App\Http\Controllers\MusicSessionController;
use App\Services\Downloads\DownloadsAccess;
use App\Services\KomgaSession;
use App\Services\ReadingSession;
use App\Services\VideoSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

Route::post('reading/session', function (Request $request, ReadingSession $bridge) {
    $dto = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));

    return response()->view('reading-enter', ['dto' => $dto, 'destination' => config('portal.services.reading.url')])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
        ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'");
});

// Served through the Vídeo gateway: the page runs on Jellyfin's origin and stores the session where
// its web interface keeps its own login (docker/video-proxy/public/enter.js). Single-use ticket.
Route::post('video/session', function (Request $request, VideoSession $bridge) {
    $session = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));
    if ($session === null) {
        return redirect()->away(rtrim((string) config('app.url'), '/').'/services/video/confirm', 303)->header('Cache-Control', 'no-store');
    }

    return response()->view('video-enter', ['session' => $session])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
        ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'");
})->middleware('throttle:30,1');

Route::get('music/portal', fn () => redirect()->away(rtrim((string) config('app.url'), '/').'/dashboard'));
Route::get('music/session/authorize', [MusicSessionController::class, 'authorize']);
Route::post('music/session/verify', MusicSessionController::class)->middleware('throttle:120,1');

// This response is served through the Komga gateway, so cookies belong to its origin.
Route::post('komga/session', function (Request $request, KomgaSession $bridge) {
    $session = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));
    $secure = parse_url((string) config('portal.services.komga.url'), PHP_URL_SCHEME) === 'https';
    $response = response('', 303)->header('Location', $session === null ? rtrim((string) config('app.url'), '/').'/services/komga/confirm' : '/')
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    $response->headers->setCookie(Cookie::create('KOMGA-SESSION', $session, $session === null ? 1 : 0)
        ->withSecure($secure)->withHttpOnly(true)->withSameSite('lax')->withRaw(true));
    $response->headers->setCookie(Cookie::create('komga-remember-me', null, 1)
        ->withSecure($secure)->withHttpOnly(true)->withSameSite('lax'));

    return $response;
})->middleware('throttle:30,1');

// Descargas (docs/DOWNLOADS.md). Served through its gateway: the cookie belongs to that origin.
Route::post('downloads/session', function (Request $request, DownloadsAccess $access) {
    $session = $access->redeem((string) $request->input('ticket'), (string) $request->header('Origin'));
    $response = response('', 303)->header('Location', $session === null ? rtrim((string) config('app.url'), '/').'/dashboard' : '/')
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    $secure = parse_url((string) config('portal.services.downloads.url'), PHP_URL_SCHEME) === 'https';
    $response->headers->setCookie(Cookie::create(DownloadsAccess::COOKIE, $session, $session === null ? 1 : now()->addMinutes(DownloadsAccess::SESSION_MINUTES))
        ->withSecure($secure)->withHttpOnly(true)->withSameSite('lax')->withRaw(true));

    return $response;
})->middleware('throttle:30,1');
// Asked by the gateway on every request (never by browsers directly with a valid session cookie).
Route::get('downloads/check', fn (Request $request, DownloadsAccess $access) => response('', $access->holder((string) $request->header('X-Multisuite-Downloads', '')) !== null ? 204 : 401)
    ->header('Cache-Control', 'no-store'));
Route::get('downloads/portal', fn () => redirect()->away(rtrim((string) config('app.url'), '/').'/services/downloads'));
