<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubscribeLb\Controllers\AdminController;

// 控制台页面挂在后台路径下（不暴露后台路径）；页面本身只是静态壳，数据全走下面带后台登录 token 的接口
$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

Route::get('/' . $securePath . '/sub-lb', [AdminController::class, 'console']);
