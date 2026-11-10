<?php

namespace App\Http\Controllers;

use App\Services\MusicSessionBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MusicSessionController extends Controller
{
    public function __invoke(Request $request, MusicSessionBridge $bridge): JsonResponse
    {
        abort_unless($request->isJson(), 415);
        $data = $request->validate(['ticket' => ['required', 'string', 'max:4096']]);

        return response()->json($bridge->verify($data['ticket']))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
