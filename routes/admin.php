<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentRoutineController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CapabilityController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Support\Facades\Route;

// Super admin console (docs/DECISOES.md), on config('tenancy.admin_domain').
// Routes under {tenant} run inside that tenant (SetTenantFromRoute).

Route::middleware('guest:admin')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth:admin')->group(function () {
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
    Route::redirect('/', '/tenants');

    Route::resource('tenants', TenantController::class)->only(['index', 'create', 'store', 'show', 'update']);

    Route::prefix('tenants/{tenant}')->name('tenants.')->group(function () {
        Route::post('agents/templates', [AgentController::class, 'installTemplates'])->name('agents.templates');
        Route::resource('agents', AgentController::class)->only(['create', 'store', 'edit', 'update']);
        Route::put('agents/{agent}/mailbox', [AgentController::class, 'updateMailbox'])->name('agents.mailbox');
        Route::resource('agents.routines', AgentRoutineController::class)->only(['store', 'update', 'destroy']);

        Route::get('capabilities', [CapabilityController::class, 'index'])->name('capabilities.index');
        Route::put('capabilities/{capability}', [CapabilityController::class, 'update'])->name('capabilities.update');
        Route::post('capabilities/sync', [CapabilityController::class, 'sync'])->middleware('throttle:10,1')->name('capabilities.sync');
    });
});
