<?php

use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\InitialPasswordController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ManualUserController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\UsernameController;
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
    Route::post('settings/username', [UsernameController::class, 'update'])->middleware('throttle:5,1')->name('username.update');
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('services/{service}', ServiceController::class)->where('service', '[a-z0-9-]+')->name('services.open');

    Route::middleware(['can:manage-invitations', 'password.confirm', 'throttle:10,1'])->group(function () {
        Route::get('admin/activity', AdminAuditController::class)->middleware('can:view-admin-audit')->name('audit.index');
        Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
        Route::get('users/create', [ManualUserController::class, 'create'])->name('users.create');
        Route::post('users', [ManualUserController::class, 'store'])->name('users.store');
        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'revoke'])->name('invitations.revoke');
    });
});

require __DIR__.'/settings.php';
