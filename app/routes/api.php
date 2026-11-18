<?php

use App\Http\Controllers\MusicSessionController;
use App\Services\KomgaSession;
use App\Services\ReadingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

Route::post('reading/session', function (Request $request, ReadingSession $bridge) {
    $dto = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));

    return response()->view('reading-enter', ['dto' => $dto, 'destination' => config('portal.services.reading.url')])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
        ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'");
});

Route::get('music/portal', fn () => redirect()->away(rtrim((string) config('app.url'), '/').'/dashboard'));
Route::get('music/session/authorize', [MusicSessionController::class, 'authorize']);
Route::post('music/session/verify', MusicSessionController::class)->middleware('throttle:120,1');


// This response is served through the Komga gateway, so cookies belong to its origin.
Route::post('komga/session', function (Request $request, KomgaSession $bridge) {
    $session = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));
    $secure = parse_url((string) config('portal.services.komga.url'), PHP_URL_SCHEME) === 'https';
    $response = response('', 303)->header('Location', $session === null ? '/login?multisuite=manual' : '/')
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    $response->headers->setCookie(Cookie::create('KOMGA-SESSION', $session, $session === null ? 1 : 0)
        ->withSecure($secure)->withHttpOnly(true)->withSameSite('lax'));
    $response->headers->setCookie(Cookie::create('komga-remember-me', null, 1)
        ->withSecure($secure)->withHttpOnly(true)->withSameSite('lax'));

    return $response;
})->middleware('throttle:30,1');
