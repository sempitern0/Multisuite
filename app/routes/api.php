<?php

use App\Http\Controllers\MusicSessionController;
use Illuminate\Support\Facades\Route;

Route::get('music/session/authorize', [MusicSessionController::class, 'authorize']);
Route::post('music/session/verify', MusicSessionController::class)->middleware('throttle:120,1');
