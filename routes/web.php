<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\ErpConnectionController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

// Every web route runs on a tenant host; IdentifyTenant is prepended to the web group.

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::get('erp', [ErpConnectionController::class, 'show'])->name('erp.show');
        Route::put('erp', [ErpConnectionController::class, 'update'])->name('erp.update');
        Route::post('erp/test', [ErpConnectionController::class, 'test'])->middleware('throttle:10,1')->name('erp.test');
    });
});
