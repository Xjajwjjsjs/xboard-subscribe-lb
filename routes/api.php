<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubscribeLb\Controllers\AdminController;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

Route::prefix('api/v2/' . $securePath . '/sub-lb')->middleware('admin')->group(function () {
    Route::get('/fetch', [AdminController::class, 'fetch']);
    Route::get('/servers', [AdminController::class, 'servers']);
    Route::get('/events', [AdminController::class, 'events']);
    Route::get('/user', [AdminController::class, 'userLookup']);
    Route::post('/group/save', [AdminController::class, 'groupSave']);
    Route::post('/group/toggle', [AdminController::class, 'groupToggle']);
    Route::post('/group/drop', [AdminController::class, 'groupDrop']);
    Route::post('/member/add', [AdminController::class, 'memberAdd']);
    Route::post('/member/update', [AdminController::class, 'memberUpdate']);
    Route::post('/member/health', [AdminController::class, 'memberHealth']);
    Route::post('/member/drop', [AdminController::class, 'memberDrop']);
    Route::post('/reset', [AdminController::class, 'reset']);
});
