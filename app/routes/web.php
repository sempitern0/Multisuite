<?php

use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('services/{service}', ServiceController::class)->where('service', '[a-z0-9-]+')->name('services.open');
});

require __DIR__.'/settings.php';
