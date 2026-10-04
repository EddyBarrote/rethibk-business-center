<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Knowledge\DomainController;
use App\Http\Controllers\Knowledge\FolderController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Settings\BrandController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\ErpConnectionController;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TenderController;
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

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:30,1')->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/messages', [TaskController::class, 'message'])->middleware('throttle:30,1')->name('tasks.message');
    Route::post('agents/{agent}/chat', [TaskController::class, 'chat'])->middleware('throttle:20,1')->name('agents.chat');
    Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('goals', [GoalController::class, 'store'])->name('goals.store');
    Route::put('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::get('org', [OrgController::class, 'index'])->name('org.index');
    Route::put('org/{agent}', [OrgController::class, 'update'])->name('org.update');
    Route::get('runs', [AgentRunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('knowledge/new', [KnowledgeController::class, 'create'])->name('knowledge.create');
    Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::post('knowledge/upload', [KnowledgeController::class, 'upload'])->middleware('throttle:30,1')->name('knowledge.upload');
    Route::get('knowledge/domains', [DomainController::class, 'index'])->name('knowledge.domains.index');
    Route::post('knowledge/domains', [DomainController::class, 'store'])->name('knowledge.domains.store');
    Route::put('knowledge/domains/{domain}', [DomainController::class, 'update'])->name('knowledge.domains.update');
    Route::delete('knowledge/domains/{domain}', [DomainController::class, 'destroy'])->name('knowledge.domains.destroy');
    Route::post('knowledge/folders', [FolderController::class, 'store'])->name('knowledge.folders.store');
    Route::put('knowledge/folders/{folder}', [FolderController::class, 'update'])->name('knowledge.folders.update');
    Route::delete('knowledge/folders/{folder}', [FolderController::class, 'destroy'])->name('knowledge.folders.destroy');
    Route::get('knowledge/{item}', [KnowledgeController::class, 'show'])->name('knowledge.show');
    Route::get('knowledge/{item}/edit', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
    Route::put('knowledge/{item}', [KnowledgeController::class, 'update'])->name('knowledge.update');
    Route::delete('knowledge/{item}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
    Route::post('knowledge/{item}/review', [KnowledgeController::class, 'review'])->name('knowledge.review');
    Route::get('knowledge/{item}/file', [KnowledgeController::class, 'file'])->name('knowledge.file');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:20,1')->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('documents/{document}/convert', [DocumentController::class, 'convert'])->middleware('throttle:20,1')->name('documents.convert');
    Route::post('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('reports/{report}/export', [DocumentController::class, 'fromReport'])->middleware('throttle:20,1')->name('reports.export');

    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{message}', [InboxController::class, 'show'])->name('inbox.show');
    Route::post('inbox/{message}/send', [InboxController::class, 'send'])->middleware('throttle:30,1')->name('inbox.send');
    Route::delete('inbox/{message}', [InboxController::class, 'discard'])->name('inbox.discard');
    Route::put('inbox/{message}/category', [InboxController::class, 'reclassify'])->name('inbox.reclassify');
    Route::post('inbox/{message}/retriage', [InboxController::class, 'retriage'])->middleware('throttle:20,1')->name('inbox.retriage');
    Route::get('attachments/{attachment}', [InboxController::class, 'attachment'])->name('attachments.download');

    Route::get('tenders', [TenderController::class, 'index'])->name('tenders.index');
    Route::put('tenders/{tender}', [TenderController::class, 'update'])->name('tenders.update');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');

    Route::get('briefings', [BriefingController::class, 'index'])->name('briefings.index');
    Route::get('briefings/{briefing}', [BriefingController::class, 'show'])->name('briefings.show');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::post('reports/{report}/review', [ReportController::class, 'review'])->name('reports.review');

    Route::get('finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('finance/statements', [FinanceController::class, 'upload'])->name('finance.upload');
    Route::post('finance/transactions/{transaction}', [FinanceController::class, 'reconcile'])->name('finance.reconcile');
    Route::post('finance/ask', [FinanceController::class, 'ask'])->middleware('throttle:10,1')->name('finance.ask');

    Route::get('procurement', [ProcurementController::class, 'index'])->name('procurement.index');
    Route::post('procurement', [ProcurementController::class, 'store'])->name('procurement.store');
    Route::get('procurement/{purchase}', [ProcurementController::class, 'show'])->name('procurement.show');
    Route::post('procurement/{purchase}/cancel', [ProcurementController::class, 'cancel'])->name('procurement.cancel');

    Route::resource('contracts', ContractController::class)->only(['index', 'show', 'store', 'update']);

    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/{account}', [ClientController::class, 'show'])->name('clients.show');
    Route::post('clients/{account}/brief', [ClientController::class, 'brief'])->middleware('throttle:10,1')->name('clients.brief');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::get('brand', [BrandController::class, 'show'])->name('brand.show');
        Route::post('brand', [BrandController::class, 'update'])->name('brand.update');
        Route::get('brand/logo', [BrandController::class, 'logo'])->name('brand.logo');

        Route::get('erp', [ErpConnectionController::class, 'show'])->name('erp.show');
        Route::put('erp', [ErpConnectionController::class, 'update'])->name('erp.update');
        Route::post('erp/test', [ErpConnectionController::class, 'test'])->middleware('throttle:10,1')->name('erp.test');
    });
});
