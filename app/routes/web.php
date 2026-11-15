<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileExportController;
use App\Http\Controllers\InitialPasswordController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PrivateUploadController;
use App\Http\Controllers\ServiceController;
use App\Http\Middleware\RestrictPreviewImages;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No landing page: guests go straight to sign in, signed-in people to their start page.
Route::get('/', function (Request $request): RedirectResponse {
    $user = $request->user();

    return redirect()->route($user instanceof User ? $user->preferences()->startRoute() : 'login');
})->name('home');

Route::middleware(['guest', 'signed', 'throttle:invitation-accept'])->group(function () {
    Route::get('invitations/accept/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('invitation.accept');
    Route::post('invitations/accept/{token}', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}');
});

Route::middleware(['auth'])->group(function () {
    Route::get('password/initial', [InitialPasswordController::class, 'edit'])->name('password.initial.edit');
    Route::post('password/initial', [InitialPasswordController::class, 'update'])->middleware('throttle:account-change')->name('password.initial.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Mis archivos: owner-scoped lookups and policies; never under /storage/ (Nginx public disk).
    // Pages are Livewire and the only entry to file operations (actions use ThrottlesActions).
    // The controller keeps the non-page endpoints: upload registration (JSON) and binary content.
    Route::prefix('files')->name('files.')->middleware('throttle:files')->group(function () {
        Route::livewire('/', 'pages::files.index')->name('index');
        Route::livewire('folder/{folderId}', 'pages::files.index')->whereNumber('folderId')->name('folder');
        Route::post('/', [FileController::class, 'store'])->middleware('throttle:uploads')->name('store');
        Route::livewire('exports', 'pages::files.exports')->name('exports.index');
        Route::get('exports/{exportId}/download', [FileExportController::class, 'download'])->whereUuid('exportId')->name('exports.download');
        Route::livewire('trash', 'pages::files.trash')->name('trash');
        Route::livewire('{fileId}', 'pages::files.show')->whereNumber('fileId')->middleware(RestrictPreviewImages::class)->name('show');
        Route::get('{fileId}/content', [FileController::class, 'content'])->whereNumber('fileId')->name('content');
    });
    Route::prefix('uploads')->group(function () {
        Route::post('/', [PrivateUploadController::class, 'store'])->middleware('throttle:uploads');
        Route::get('{key}', [PrivateUploadController::class, 'show'])->whereUuid('key')->middleware('throttle:uploads');
        // One request per 8 MiB chunk: its own limiter, sized above a gigabit link.
        Route::put('{key}', [PrivateUploadController::class, 'update'])->whereUuid('key')->middleware('throttle:upload-chunks');
        Route::delete('{key}', [PrivateUploadController::class, 'destroy'])->whereUuid('key')->middleware('throttle:uploads');
    });
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // Profile photos from the private disk; never under /storage/ (Nginx public disk).
    Route::get('avatars/{user}/{hash}', AvatarController::class)->whereNumber('user')->where('hash', '[a-f0-9]{20}')->name('avatars.show');
    Route::get('services/{service}', ServiceController::class)->where('service', '[a-z0-9-]+')->name('services.open');

    Route::middleware(['can:manage-invitations', 'password.confirm', 'throttle:admin'])->group(function () {
        // Administration is Livewire: each page authorises on every request and calls the Actions.
        // The route middleware guards opening the page; actions use ThrottlesActions with the same limiter.
        Route::livewire('admin/activity', 'pages::admin.activity')->middleware('can:view-admin-audit')->name('audit.index');
        Route::livewire('users', 'pages::admin.users')->name('users.index');
        Route::livewire('users/{user}/edit', 'pages::admin.user-edit')->name('users.edit');
        Route::livewire('users/create', 'pages::admin.user-create')->name('users.create');
        Route::livewire('invitations', 'pages::admin.invitations')->name('invitations.index');
    });

    // Storage limits: same audience, but a higher rate so a family can be configured in one sitting.
    // Never under /storage/: Nginx serves that prefix as the public disk (docker/nginx/site.conf).
    Route::middleware(['can:view-storage', 'password.confirm', 'throttle:admin-storage'])->prefix('admin/storage')->name('storage.')->group(function () {
        Route::livewire('/', 'pages::admin.storage')->name('index');
    });
});

require __DIR__.'/settings.php';
