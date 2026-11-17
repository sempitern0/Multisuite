<?php

use App\Http\Controllers\MusicSessionController;
use App\Services\ReadingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('reading/session', function (Request $request, ReadingSession $bridge) {
    $dto = $bridge->exchange((string) $request->input('ticket'), (string) $request->header('Origin'));

    return response()->view('reading-enter', ['dto' => $dto, 'destination' => config('portal.services.reading.url')])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
        ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'");
});

Route::get('music/portal', fn () => redirect()->away(rtrim((string) config('app.url'), '/').'/dashboard'));
Route::get('music/session/authorize', [MusicSessionController::class, 'authorize']);
Route::post('music/session/verify', MusicSessionController::class)->middleware('throttle:120,1');
