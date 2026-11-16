<?php

namespace App\Http\Controllers;

use App\Services\MusicSessionBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

class MusicSessionController extends Controller
{
    public function authorize(Request $request, MusicSessionBridge $bridge): Response
    {
        $username = $bridge->authorize((string) $request->header('X-Music-Grant'), $request->header('X-Music-Origin'));

        return response()->noContent()->header('X-Music-User', $username)->header('Cache-Control', 'no-store');
    }

    public function __invoke(Request $request, MusicSessionBridge $bridge): JsonResponse
    {
        abort_unless($request->isJson(), 415);
        $data = $request->validate(['ticket' => ['required', 'string', 'max:4096']]);

        $identity = $bridge->verify($data['ticket'], $grant, $request->header('X-Music-Browser'), $newNonce);
        if ($grant !== null) {
            $identity['autologin'] = true;
        }
        $response = response()->json($identity)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
        if ($newNonce !== null) {
            $response->headers->setCookie(Cookie::create('ms_music_browser', $newNonce, now()->addMinutes(2),
                '/_multisuite/verify', null, str_starts_with((string) config('portal.services.music.url'), 'https://'),
                true, false, 'strict'));
        }
        if ($grant !== null) {
            $response->headers->setCookie(Cookie::create('ms_music', $grant, 0, '/', null,
                str_starts_with((string) config('portal.services.music.url'), 'https://'), true, false, 'strict'));
        }

        return $response;
    }
}
