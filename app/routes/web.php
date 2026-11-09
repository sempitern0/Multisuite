<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ManualUserController;
use App\Http\Controllers\InitialPasswordController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['guest', 'signed', 'throttle:10,1'])->group(function () {
    Route::get('invitations/accept/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('invitation.accept');
    Route::post('invitations/accept/{token}', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}');
});

Route::middleware(['auth'])->group(function () {
    Route::get('password/initial', [InitialPasswordController::class, 'edit'])->name('password.initial.edit');
    Route::post('password/initial', [InitialPasswordController::class, 'update'])->middleware('throttle:5,1')->name('password.initial.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('services/{service}', ServiceController::class)->where('service', '[a-z0-9-]+')->name('services.open');

    Route::middleware(['can:manage-invitations', 'password.confirm', 'throttle:10,1'])->group(function () {
        Route::get('users/create', [ManualUserController::class, 'create'])->name('users.create');
        Route::post('users', [ManualUserController::class, 'store'])->name('users.store');
        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'revoke'])->name('invitations.revoke');
    });
});

require __DIR__.'/settings.php';
