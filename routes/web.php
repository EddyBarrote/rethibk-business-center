<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KnowledgeController;
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

    Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::post('agents/{agent}/runs', [AgentController::class, 'run'])->middleware('throttle:20,1')->name('agents.run');
    Route::put('agents/{agent}/status', [AgentController::class, 'updateStatus'])->name('agents.status');
    Route::put('agents/{agent}/assignees', [AgentController::class, 'updateAssignees'])->name('agents.assignees');

    Route::get('runs', [AgentRunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::get('knowledge/{item}', [KnowledgeController::class, 'show'])->name('knowledge.show');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::get('erp', [ErpConnectionController::class, 'show'])->name('erp.show');
        Route::put('erp', [ErpConnectionController::class, 'update'])->name('erp.update');
        Route::post('erp/test', [ErpConnectionController::class, 'test'])->middleware('throttle:10,1')->name('erp.test');
    });
});
