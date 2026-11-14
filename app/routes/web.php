<?php

use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileExportController;
use App\Http\Controllers\InitialPasswordController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ManualUserController;
use App\Http\Controllers\PrivateUploadController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\UsernameController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['guest', 'signed', 'throttle:invitation-accept'])->group(function () {
    Route::get('invitations/accept/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('invitation.accept');
    Route::post('invitations/accept/{token}', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}');
});

Route::middleware(['auth'])->group(function () {
    Route::get('password/initial', [InitialPasswordController::class, 'edit'])->name('password.initial.edit');
    Route::post('password/initial', [InitialPasswordController::class, 'update'])->middleware('throttle:account-change')->name('password.initial.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('settings/username', [UsernameController::class, 'update'])->middleware('throttle:account-change')->name('username.update');
    // Mis archivos: owner-scoped lookups and policies; never under /storage/ (Nginx public disk).
    Route::prefix('files')->name('files.')->middleware('throttle:files')->group(function () {
        Route::get('/', [FileController::class, 'index'])->name('index');
        Route::get('folder/{folderId}', [FileController::class, 'index'])->whereNumber('folderId')->name('folder');
        Route::post('folders', [FileController::class, 'storeFolder'])->name('folders.store');
        Route::patch('folders/{folderId}', [FileController::class, 'updateFolder'])->whereNumber('folderId')->name('folders.update');
        Route::delete('folders/{folderId}', [FileController::class, 'destroyFolder'])->whereNumber('folderId')->name('folders.destroy');
        Route::post('/', [FileController::class, 'store'])->middleware('throttle:uploads')->name('store');
        Route::get('exports', [FileExportController::class, 'index'])->name('exports.index');
        Route::post('exports', [FileExportController::class, 'store'])->middleware('throttle:file-exports')->name('exports.store');
        Route::get('exports/{exportId}/download', [FileExportController::class, 'download'])->whereUuid('exportId')->name('exports.download');
        Route::delete('exports/{exportId}', [FileExportController::class, 'destroy'])->whereUuid('exportId')->name('exports.destroy');
        Route::get('trash', [FileController::class, 'trash'])->name('trash');
        Route::delete('trash', [FileController::class, 'emptyTrash'])->name('trash.empty');
        Route::get('{fileId}', [FileController::class, 'show'])->whereNumber('fileId')->name('show');
        Route::delete('{fileId}', [FileController::class, 'destroy'])->whereNumber('fileId')->name('destroy');
        Route::post('{fileId}/restore', [FileController::class, 'restore'])->whereNumber('fileId')->name('restore');
        Route::delete('{fileId}/purge', [FileController::class, 'purge'])->whereNumber('fileId')->name('purge');
        Route::post('{fileId}/promote', [FileController::class, 'promote'])->whereNumber('fileId')->name('promote');
        Route::patch('{fileId}', [FileController::class, 'update'])->whereNumber('fileId')->name('update');
        Route::get('{fileId}/content', [FileController::class, 'content'])->whereNumber('fileId')->name('content');
    });
    Route::prefix('uploads')->middleware('throttle:uploads')->group(function () {
        Route::post('/', [PrivateUploadController::class, 'store']);
        Route::get('{key}', [PrivateUploadController::class, 'show'])->whereUuid('key');
        Route::put('{key}', [PrivateUploadController::class, 'update'])->whereUuid('key');
        Route::delete('{key}', [PrivateUploadController::class, 'destroy'])->whereUuid('key');
    });
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('services/{service}', ServiceController::class)->where('service', '[a-z0-9-]+')->name('services.open');

    Route::middleware(['can:manage-invitations', 'password.confirm', 'throttle:admin'])->group(function () {
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

    // Storage limits: same audience, but a higher rate so a family can be configured in one sitting.
    // Never under /storage/: Nginx serves that prefix as the public disk (docker/nginx/site.conf).
    Route::middleware(['can:view-storage', 'password.confirm', 'throttle:admin-storage'])->prefix('admin/storage')->name('storage.')->group(function () {
        Route::get('/', [StorageController::class, 'index'])->name('index');
        Route::put('default', [StorageController::class, 'updateDefault'])->name('default.update');
        Route::put('pools/{pool}', [StorageController::class, 'updatePool'])->where('pool', '[a-z][a-z0-9-]{0,63}')->name('pools.update');
        Route::put('users/{user}', [StorageController::class, 'updateUser'])->name('users.update');
    });
});

require __DIR__.'/settings.php';
