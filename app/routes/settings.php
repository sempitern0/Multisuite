<?php

use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\Settings\SidebarPreferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');
    Route::put('settings/sidebar', SidebarPreferenceController::class)->middleware('throttle:files')->name('preferences.sidebar');

    Route::view('settings/email', 'settings.change-email')->middleware('password.confirm')->name('email-change.edit');
    Route::post('settings/email', [EmailChangeController::class, 'requestChange'])->middleware(['password.confirm', 'throttle:account-change'])->name('email-change.request');
    Route::get('settings/email/confirm/{token}', [EmailChangeController::class, 'show'])->middleware('signed')->name('email-change.confirm');
    Route::post('settings/email/confirm/{token}', [EmailChangeController::class, 'confirm'])->middleware(['signed', 'throttle:account-change']);
    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/preferences', 'pages::settings.preferences')->name('preferences.edit');
    Route::redirect('settings/appearance', 'settings/preferences');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
