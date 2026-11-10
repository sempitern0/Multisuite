<?php

use App\Http\Controllers\MusicSessionController;
use Illuminate\Support\Facades\Route;

// Stateless verification of a short-lived encrypted launcher pass. No login token is returned.
Route::post('music/session/verify', MusicSessionController::class)->middleware('throttle:120,1');
