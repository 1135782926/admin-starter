<?php

use App\Http\Controllers\Api\Common\WangEditorUploadController;
use App\Http\Controllers\Api\V1\Admin\AdminLogController;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\AdminProfileController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AttachmentController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\MenuController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1/admin')->name('admin.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login');
    Route::get('attachments/{attachment}/file', [AttachmentController::class, 'file'])
        ->middleware('signed')->name('attachments.file');

    Route::middleware('admin.auth')->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me/menus', [MenuController::class, 'current'])->name('me.menus');
        Route::get('me/profile', [AdminProfileController::class, 'show'])->name('me.profile.show');
        Route::patch('me/profile', [AdminProfileController::class, 'update'])->name('me.profile.update');
        Route::patch('me/password', [AdminProfileController::class, 'changePassword'])
            ->middleware('throttle:admin-password')->name('me.password.update');
        Route::get('me/logs', [AdminLogController::class, 'personal'])->name('me.logs');
        Route::get('me/notifications', [AdminNotificationController::class, 'index'])->name('me.notifications.index');
        Route::patch('me/notifications/read-all', [AdminNotificationController::class, 'markAllRead'])->name('me.notifications.read-all');
        Route::patch('me/notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])
            ->whereUuid('notification')->name('me.notifications.read');
        Route::post('broadcasting/auth', [AdminNotificationController::class, 'authorizeChannel'])->name('broadcasting.auth');

        Route::get('logs', [AdminLogController::class, 'index'])->middleware('admin.permission:system.log.view')->name('logs.index');
        Route::get('logs/{log}', [AdminLogController::class, 'show'])->middleware('admin.permission:system.log.view')->name('logs.show');

        Route::get('roles/options', [RoleController::class, 'options'])->name('roles.options');
        Route::get('roles/permission-tree', [RoleController::class, 'permissionTree'])->middleware('admin.permission:system.role.view')->name('roles.permission-tree');
        Route::get('roles', [RoleController::class, 'index'])->middleware('admin.permission:system.role.view')->name('roles.index');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('admin.permission:system.role.view')->name('roles.show');
        Route::post('roles', [RoleController::class, 'store'])->middleware('admin.permission:system.role.create')->name('roles.store');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('admin.permission:system.role.update')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('admin.permission:system.role.delete')->name('roles.destroy');

        Route::get('admins', [AdminUserController::class, 'index'])->middleware('admin.permission:system.admin.view')->name('admins.index');
        Route::get('admins/{adminUser}', [AdminUserController::class, 'show'])->middleware('admin.permission:system.admin.view')->name('admins.show');
        Route::post('admins', [AdminUserController::class, 'store'])->middleware('admin.permission:system.admin.create')->name('admins.store');
        Route::patch('admins/{adminUser}', [AdminUserController::class, 'update'])->middleware('admin.permission:system.admin.update')->name('admins.update');
        Route::delete('admins/{adminUser}', [AdminUserController::class, 'destroy'])->middleware('admin.permission:system.admin.delete')->name('admins.destroy');

        Route::middleware('admin.permission:system.menu.view')->group(function (): void {
            Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
            Route::get('menus/permission-tree', [MenuController::class, 'permissionTree'])->name('menus.permission-tree');
            Route::get('menus/{menu}/deletion-impact', [MenuController::class, 'deletionImpact'])->name('menus.deletion-impact');
            Route::get('menus/{menu}', [MenuController::class, 'show'])->name('menus.show');
        });
        Route::post('menus', [MenuController::class, 'store'])->middleware('admin.permission:system.menu.create')->name('menus.store');
        Route::patch('menus/{menu}', [MenuController::class, 'update'])->middleware('admin.permission:system.menu.update')->name('menus.update');
        Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->middleware('admin.permission:system.menu.delete')->name('menus.destroy');

        Route::get('attachments', [AttachmentController::class, 'index'])->middleware('admin.permission:system.attachment.view')->name('attachments.index');
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->middleware('admin.permission:system.attachment.view')->name('attachments.show');
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware(['admin.permission:system.attachment.create', 'throttle:attachment-upload'])->name('attachments.store');
        Route::patch('attachments/{attachment}', [AttachmentController::class, 'update'])->middleware('admin.permission:system.attachment.update')->name('attachments.update');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->middleware('admin.permission:system.attachment.delete')->name('attachments.destroy');
        Route::get('attachments/{attachment}/temporary-url', [AttachmentController::class, 'temporaryUrl'])->middleware('admin.permission:system.attachment.view')->name('attachments.temporary-url');
    });
});

Route::post('common/upload/wangeditor', WangEditorUploadController::class)
    ->middleware(['admin.auth', 'admin.permission:system.attachment.create', 'throttle:attachment-upload'])
    ->name('admin.wangeditor.store');
