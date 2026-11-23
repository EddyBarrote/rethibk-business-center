<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AgentAvatarController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDefinitionController;
use App\Http\Controllers\AgentErpIdentityController;
use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\AgentValueLimitController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DictationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InitiativeController;
use App\Http\Controllers\Knowledge\DomainController;
use App\Http\Controllers\Knowledge\FolderController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\KnowledgeImportController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\Settings\AccessRoleController;
use App\Http\Controllers\Settings\AgentMemoryController;
use App\Http\Controllers\Settings\AiProviderController;
use App\Http\Controllers\Settings\ApprovalRuleController;
use App\Http\Controllers\Settings\AttachmentSettingsController;
use App\Http\Controllers\Settings\AuditController;
use App\Http\Controllers\Settings\BrandController;
use App\Http\Controllers\Settings\CompanyMemoryController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\EmailRuleController;
use App\Http\Controllers\Settings\ErpConnectionController;
use App\Http\Controllers\Settings\HealthController;
use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\MemoryController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\UsageController;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\SkillImportController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserAvatarController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

// Every web route runs on a tenant host; IdentifyTenant is prepended to the web group.

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:login')->name('password.email');
    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->middleware('throttle:login')->name('password.store');
});

// The tenant's logo on the sign-in screens (Definições > Marca); public, like any login page logo.
Route::get('login/logo', [LoginController::class, 'logo'])->name('login.logo');

// A person's agenda for Google Calendar or Outlook (docs/DECISOES.md, "Agenda"): the private link is the key.
Route::get('calendar/{token}.ics', [AgendaController::class, 'feed'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')->name('agenda.feed');

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('home');
    Route::post('morning-summary/close', [HomeController::class, 'closeMorning'])->name('home.morning.close');
    Route::post('dictation', DictationController::class)->middleware('throttle:30,1')->name('dictation');
    Route::post('initiatives/{initiative}/start', [InitiativeController::class, 'start'])->middleware('throttle:20,1')->name('initiatives.start');
    Route::post('initiatives/{initiative}/hide', [InitiativeController::class, 'hide'])->name('initiatives.hide');
    Route::get('painel', DashboardController::class)->name('dashboard');

    Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('agents/new', [AgentDefinitionController::class, 'create'])->name('agents.create');
    Route::post('agents/new/draft', [AgentDefinitionController::class, 'draft'])->middleware('throttle:10,1')->name('agents.draft');
    Route::post('agents', [AgentDefinitionController::class, 'store'])->name('agents.store');
    Route::get('agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::get('agents/{agent}/edit', [AgentDefinitionController::class, 'edit'])->name('agents.edit');
    Route::put('agents/{agent}', [AgentDefinitionController::class, 'update'])->name('agents.update');
    Route::get('agents/{agent}/avatar', [AgentAvatarController::class, 'show'])->name('agents.avatar');
    Route::post('agents/{agent}/avatar', [AgentAvatarController::class, 'store'])->name('agents.avatar.store');
    Route::post('agents/{agent}/avatar/generate', [AgentAvatarController::class, 'generate'])->middleware('throttle:6,1')->name('agents.avatar.generate');
    Route::delete('agents/{agent}/avatar', [AgentAvatarController::class, 'destroy'])->name('agents.avatar.destroy');
    Route::put('agents/{agent}/erp-identity', [AgentErpIdentityController::class, 'update'])->name('agents.erp-identity.update');
    Route::delete('agents/{agent}/erp-identity', [AgentErpIdentityController::class, 'destroy'])->name('agents.erp-identity.destroy');
    Route::post('agents/{agent}/erp-identity/test', [AgentErpIdentityController::class, 'test'])->middleware('throttle:10,1')->name('agents.erp-identity.test');
    Route::get('people/{user}/avatar', [UserAvatarController::class, 'show'])->name('people.avatar');
    Route::post('agents/{agent}/runs', [AgentController::class, 'run'])->middleware('throttle:20,1')->name('agents.run');
    Route::put('agents/{agent}/status', [AgentController::class, 'updateStatus'])->name('agents.status');
    Route::put('agents/{agent}/assignees', [AgentController::class, 'updateAssignees'])->name('agents.assignees');
    Route::put('agents/{agent}/value-limit', [AgentValueLimitController::class, 'update'])->name('agents.value-limit');
    Route::put('agents/{agent}/memories/{memory}', [AgentController::class, 'updateMemory'])->name('agents.memories.update');
    Route::delete('agents/{agent}/memories/{memory}', [AgentController::class, 'destroyMemory'])->name('agents.memories.destroy');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:30,1')->name('tasks.store');
    Route::post('tasks/bulk', [TaskController::class, 'bulk'])->name('tasks.bulk');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/collaborators', [TaskController::class, 'invite'])->name('tasks.collaborators.store');
    Route::delete('tasks/{task}/collaborators/{collaborator}', [TaskController::class, 'uninvite'])->name('tasks.collaborators.destroy');
    Route::post('tasks/{task}/close', [TaskController::class, 'close'])->name('tasks.close');
    Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry'])->middleware('throttle:10,1')->name('tasks.retry');
    Route::post('tasks/{task}/messages', [TaskController::class, 'message'])->middleware('throttle:30,1')->name('tasks.message');
    Route::post('questions/{question}/answer', [QuestionController::class, 'answer'])->middleware('throttle:30,1')->name('questions.answer');
    Route::get('agents/{agent}/chat', [TaskController::class, 'conversation'])->name('agents.conversation');
    Route::post('agents/{agent}/chat', [TaskController::class, 'chat'])->middleware('throttle:20,1')->name('agents.chat');
    // Files in conversations (docs/DECISOES.md, "Anexos nas conversas"): uploaded in parts, sent with the message.
    Route::post('files', [AttachmentController::class, 'start'])->middleware('throttle:60,1')->name('files.start');
    Route::post('files/{attachment}/chunk', [AttachmentController::class, 'chunk'])->middleware('throttle:600,1')->name('files.chunk');
    Route::delete('files/{attachment}', [AttachmentController::class, 'destroy'])->name('files.destroy');
    Route::get('files/{attachment}', [AttachmentController::class, 'show'])->name('files.show');
    Route::get('files/{attachment}/preview', [AttachmentController::class, 'preview'])->middleware('throttle:60,1')->name('files.preview');
    Route::get('agenda', [AgendaController::class, 'index'])->name('agenda.index');
    Route::post('agenda/follow-ups', [AgendaController::class, 'store'])->middleware('throttle:30,1')->name('agenda.follow-ups.store');
    Route::patch('agenda/follow-ups/{followUp}', [AgendaController::class, 'update'])->name('agenda.follow-ups.update');
    Route::delete('agenda/follow-ups/{followUp}', [AgendaController::class, 'destroy'])->name('agenda.follow-ups.destroy');
    Route::post('agenda/feed', [AgendaController::class, 'createFeed'])->middleware('throttle:10,1')->name('agenda.feed.create');
    Route::delete('agenda/feed', [AgendaController::class, 'destroyFeed'])->name('agenda.feed.destroy');
    // Google Calendar per person (docs/DECISOES.md, "Google Calendar por pessoa"); Google answers on the central domain (bootstrap/app.php).
    Route::get('agenda/google/connect', [GoogleCalendarController::class, 'connect'])->middleware('throttle:10,1')->name('agenda.google.connect');
    Route::post('agenda/google/sync', [GoogleCalendarController::class, 'sync'])->middleware('throttle:10,1')->name('agenda.google.sync');
    Route::delete('agenda/google', [GoogleCalendarController::class, 'destroy'])->name('agenda.google.destroy');
    Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('goals', [GoalController::class, 'store'])->name('goals.store');
    Route::put('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('projects/{project}/channel', [ProjectController::class, 'channel'])->middleware('throttle:10,1')->name('projects.channel');
    Route::get('org', [OrgController::class, 'index'])->name('org.index');
    Route::put('org', [OrgController::class, 'update'])->name('org.update');
    // Fluxos de trabalho (docs/DECISOES.md): the map, and the editor (canvas and list).
    Route::post('workflow-steps/{step}/decide', [WorkflowController::class, 'decide'])->middleware('throttle:30,1')->name('workflow-steps.decide');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/approve', [ApprovalController::class, 'approveMany'])->middleware('throttle:20,1')->name('approvals.approve-many');
    Route::post('approvals/reject', [ApprovalController::class, 'rejectMany'])->middleware('throttle:20,1')->name('approvals.reject-many');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('knowledge/new', [KnowledgeController::class, 'create'])->name('knowledge.create');
    Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::post('knowledge/upload', [KnowledgeController::class, 'upload'])->middleware('throttle:30,1')->name('knowledge.upload');
    Route::post('knowledge/import', KnowledgeImportController::class)->middleware('throttle:30,1')->name('knowledge.import');
    Route::get('knowledge/import/options', [KnowledgeImportController::class, 'options'])->name('knowledge.import.options');
    // Moved to Definições › Memória (14.10.2026); before knowledge/{item}, which would catch it.
    Route::redirect('knowledge/company', '/settings/company-memory');
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
    Route::get('knowledge/{item}/thumbnail', [KnowledgeController::class, 'thumbnail'])->name('knowledge.thumbnail');
    Route::post('knowledge/{item}/version', [KnowledgeController::class, 'version'])->middleware('throttle:30,1')->name('knowledge.version');
    Route::get('knowledge/{item}/versions/{version}/file', [KnowledgeController::class, 'versionFile'])->name('knowledge.versions.file');
    Route::post('knowledge/{item}/reread', [KnowledgeController::class, 'reread'])->middleware('throttle:20,1')->name('knowledge.reread');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:20,1')->name('documents.store');
    Route::post('documents/folders', [DocumentController::class, 'storeFolder'])->name('documents.folders.store');
    Route::put('documents/folders/{folder}', [DocumentController::class, 'updateFolder'])->name('documents.folders.update');
    Route::delete('documents/folders/{folder}', [DocumentController::class, 'destroyFolder'])->name('documents.folders.destroy');
    Route::patch('documents/bulk/folder', [DocumentController::class, 'bulkMove'])->name('documents.bulk.move');
    Route::get('documents/bulk/download', [DocumentController::class, 'bulkDownload'])->name('documents.bulk.download');
    Route::post('documents/bulk/file', [DocumentController::class, 'bulkFile'])->name('documents.bulk.file');
    Route::delete('documents/bulk', [DocumentController::class, 'bulkDestroy'])->name('documents.bulk.destroy');
    Route::patch('documents/{document}/folder', [DocumentController::class, 'move'])->name('documents.move');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('documents/{document}/convert', [DocumentController::class, 'convert'])->middleware('throttle:20,1')->name('documents.convert');
    Route::post('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('reports/{report}/export', [DocumentController::class, 'fromReport'])->middleware('throttle:20,1')->name('reports.export');

    // Email: the email client of each person's mailboxes, triage included for who may read it.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/sync', [InboxController::class, 'sync'])->middleware('throttle:30,1')->name('inbox.sync');
    Route::post('inbox/folders/{folder}/older', [InboxController::class, 'older'])->middleware('throttle:20,1')->name('inbox.older');
    Route::post('inbox/drafts', [InboxController::class, 'saveDraft'])->middleware('throttle:120,1')->name('inbox.drafts.store');
    Route::put('inbox/drafts/{message}', [InboxController::class, 'saveDraft'])->middleware('throttle:120,1')->name('inbox.drafts.update');
    Route::post('inbox/drafts/{message}/keep', [InboxController::class, 'keepDraft'])->middleware('throttle:60,1')->name('inbox.drafts.keep');
    Route::post('inbox/send', [InboxController::class, 'send'])->middleware('throttle:30,1')->name('inbox.compose.send');
    Route::get('inbox/{message}', [InboxController::class, 'show'])->name('inbox.show');
    Route::post('inbox/{message}/send', [InboxController::class, 'send'])->middleware('throttle:30,1')->name('inbox.send');
    Route::post('inbox/{message}/read', [InboxController::class, 'read'])->name('inbox.read');
    Route::post('inbox/{message}/flag', [InboxController::class, 'flag'])->name('inbox.flag');
    Route::post('inbox/{message}/move', [InboxController::class, 'move'])->name('inbox.move');
    Route::post('inbox/{message}/archive', [InboxController::class, 'archive'])->name('inbox.archive');
    Route::delete('inbox/{message}', [InboxController::class, 'destroy'])->name('inbox.destroy');
    Route::put('inbox/{message}/category', [InboxController::class, 'reclassify'])->name('inbox.reclassify');
    Route::post('inbox/{message}/agent', [InboxController::class, 'talk'])->middleware('throttle:20,1')->name('inbox.agent');
    Route::post('inbox/{message}/retriage', [InboxController::class, 'retriage'])->middleware('throttle:20,1')->name('inbox.retriage');
    Route::get('attachments/{attachment}', [InboxController::class, 'attachment'])->name('attachments.download');
    Route::get('attachments/{attachment}/preview', [InboxController::class, 'attachmentPreview'])->middleware('throttle:60,1')->name('attachments.preview');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');

    Route::get('briefings', [BriefingController::class, 'index'])->name('briefings.index');
    Route::get('briefings/{briefing}', [BriefingController::class, 'show'])->name('briefings.show');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::post('reports/{report}/review', [ReportController::class, 'review'])->name('reports.review');

    // Definições: every setting in one place, grouped (docs/DECISOES.md, "Definições num só lugar").
    // Route names stay as they were; only the addresses moved under /settings.
    Route::prefix('settings')->group(function () {
        Route::get('/', SettingsController::class)->name('settings.index');

        // Empresa
        Route::get('brand', [BrandController::class, 'show'])->name('settings.brand.show');
        Route::post('brand', [BrandController::class, 'update'])->name('settings.brand.update');
        Route::get('brand/logo', [BrandController::class, 'logo'])->name('settings.brand.logo');
        Route::post('brand/assets', [BrandController::class, 'storeAsset'])->name('settings.brand.assets.store');
        Route::get('brand/assets/{key}', [BrandController::class, 'asset'])->name('settings.brand.assets.show');
        Route::delete('brand/assets/{key}', [BrandController::class, 'destroyAsset'])->name('settings.brand.assets.destroy');
        Route::get('ai-providers', [AiProviderController::class, 'show'])->name('settings.ai-providers.show');
        Route::put('ai-providers/{provider}', [AiProviderController::class, 'update'])->name('settings.ai-providers.update');
        Route::delete('ai-providers/{provider}', [AiProviderController::class, 'destroy'])->name('settings.ai-providers.destroy');
        Route::get('attachments', [AttachmentSettingsController::class, 'show'])->name('settings.attachments.show');
        Route::put('attachments', [AttachmentSettingsController::class, 'update'])->name('settings.attachments.update');
        Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy'])->names('settings.departments');
        // Auditoria (docs/DECISOES.md, "Auditoria pesquisável e exportável")
        Route::get('audit', [AuditController::class, 'index'])->name('settings.audit.index');
        Route::get('audit/export', [AuditController::class, 'export'])->middleware('throttle:10,1')->name('settings.audit.export');

        // Pessoas e acessos
        Route::resource('users', UserController::class)->except(['show', 'destroy'])->names('settings.users');
        Route::get('roles', [AccessRoleController::class, 'index'])->name('settings.roles.index');
        Route::post('roles', [AccessRoleController::class, 'store'])->name('settings.roles.store');
        Route::put('roles/{role}', [AccessRoleController::class, 'update'])->name('settings.roles.update');
        Route::delete('roles/{role}', [AccessRoleController::class, 'destroy'])->name('settings.roles.destroy');

        // Comunicação: mailboxes, and the ERP and connectors together as Integrações
        Route::get('mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
        Route::post('mailboxes', [MailboxController::class, 'store'])->name('mailboxes.store');
        Route::put('mailboxes/{mailbox}', [MailboxController::class, 'update'])->name('mailboxes.update');
        Route::delete('mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->name('mailboxes.destroy');
        Route::get('integrations', [IntegrationController::class, 'index'])->name('settings.integrations.index');
        Route::get('integrations/erp', [ErpConnectionController::class, 'show'])->name('settings.erp.show');
        Route::get('integrations/erp/activity', [ErpConnectionController::class, 'activity'])->name('settings.erp.activity');
        Route::put('integrations/erp', [ErpConnectionController::class, 'update'])->name('settings.erp.update');
        Route::post('integrations/erp/test', [ErpConnectionController::class, 'test'])->middleware('throttle:10,1')->name('settings.erp.test');
        Route::post('integrations/connectors', [ConnectorController::class, 'store'])->middleware('throttle:20,1')->name('connectors.store');
        Route::put('integrations/connectors/{connector}', [ConnectorController::class, 'update'])->middleware('throttle:20,1')->name('connectors.update');
        Route::post('integrations/connectors/{connector}/refresh', [ConnectorController::class, 'refresh'])->middleware('throttle:10,1')->name('connectors.refresh');
        Route::delete('integrations/connectors/{connector}', [ConnectorController::class, 'destroy'])->name('connectors.destroy');
        Route::post('integrations/global/{connector}', [CapabilityController::class, 'activate'])->middleware('throttle:10,1')->name('capabilities.global.activate');
        Route::delete('integrations/global/{connector}', [CapabilityController::class, 'deactivate'])->name('capabilities.global.deactivate');

        // Agentes de IA (docs/CAPACIDADES.md)
        Route::get('capabilities', [CapabilityController::class, 'index'])->name('capabilities.index');
        Route::patch('capabilities/{capability}', [CapabilityController::class, 'update'])->name('capabilities.update');
        Route::post('capabilities/sync', [CapabilityController::class, 'sync'])->middleware('throttle:10,1')->name('capabilities.sync');
        Route::post('skills/import', [SkillImportController::class, 'store'])->name('skills.import');
        Route::get('skills/import/{token}', [SkillImportController::class, 'show'])->name('skills.import.show');
        Route::post('skills/import/{token}', [SkillImportController::class, 'confirm'])->name('skills.import.confirm');
        Route::delete('skills/import/{token}', [SkillImportController::class, 'destroy'])->name('skills.import.destroy');
        Route::resource('skills', SkillController::class)->except(['show']);
        Route::put('skills/{skill}/agents', [SkillController::class, 'updateAgents'])->name('skills.agents');
        Route::put('skills/global/{platformSkill}', [SkillController::class, 'toggleGlobal'])->name('skills.global');
        Route::post('skills/{skill}/files', [SkillController::class, 'storeFile'])->name('skills.files.store');
        Route::delete('skills/{skill}/files/{file}', [SkillController::class, 'destroyFile'])->name('skills.files.destroy');
        // Fluxos de trabalho, Rotinas e Execuções (docs/DECISOES.md, "Fluxos, Rotinas e Execuções nas Definições")
        Route::get('workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('workflows/new', [WorkflowController::class, 'create'])->name('workflows.create');
        Route::post('workflows', [WorkflowController::class, 'store'])->name('workflows.store');
        Route::post('workflows/check', [WorkflowController::class, 'check'])->middleware('throttle:120,1')->name('workflows.check');
        Route::post('workflows/draft', [WorkflowController::class, 'draft'])->middleware('throttle:10,1')->name('workflows.draft');
        Route::get('workflows/describe', [WorkflowController::class, 'describe'])->name('workflows.describe');
        Route::post('workflows/draft/stream', [WorkflowController::class, 'draftStream'])->middleware('throttle:10,1')->name('workflows.draft-stream');
        Route::get('workflows/{workflow}/edit', [WorkflowController::class, 'edit'])->name('workflows.edit');
        Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
        Route::put('workflows/{workflow}/status', [WorkflowController::class, 'status'])->name('workflows.status');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
        Route::get('routines', [RoutineController::class, 'index'])->name('routines.index');
        Route::post('routines', [RoutineController::class, 'store'])->name('routines.store');
        Route::put('routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
        Route::delete('routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
        Route::post('routines/{routine}/run', [RoutineController::class, 'run'])->middleware('throttle:10,1')->name('routines.run');
        Route::get('runs', [AgentRunController::class, 'index'])->name('runs.index');
        Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');
        Route::get('usage', UsageController::class)->name('settings.usage');
        Route::get('email-rules', [EmailRuleController::class, 'index'])->name('settings.email-rules.index');
        Route::get('health', [HealthController::class, 'show'])->name('settings.health');
        Route::post('health', [HealthController::class, 'check'])->middleware('throttle:6,1')->name('settings.health.check');
        Route::get('approval-rules', [ApprovalRuleController::class, 'index'])->name('settings.approval-rules.index');
        Route::post('approval-rules', [ApprovalRuleController::class, 'store'])->name('settings.approval-rules.store');
        Route::put('approval-rules/{rule}', [ApprovalRuleController::class, 'update'])->name('settings.approval-rules.update');
        Route::delete('approval-rules/{rule}', [ApprovalRuleController::class, 'destroy'])->name('settings.approval-rules.destroy');
        Route::put('email-rules/{category}', [EmailRuleController::class, 'update'])->name('settings.email-rules.update');

        // A minha conta
        Route::get('profile', [ProfileController::class, 'show'])->name('settings.profile.show');
        Route::put('profile', [ProfileController::class, 'update'])->name('settings.profile.update');
        Route::put('profile/email-images', [ProfileController::class, 'emailImages'])->name('settings.profile.email-images');
        Route::put('profile/mode', [ProfileController::class, 'mode'])->name('settings.profile.mode');
        Route::post('profile/avatar', [UserAvatarController::class, 'store'])->middleware('throttle:20,1')->name('settings.profile.avatar');
        Route::delete('profile/avatar', [UserAvatarController::class, 'destroy'])->name('settings.profile.avatar.destroy');
        Route::get('notifications', [NotificationPreferencesController::class, 'show'])->name('settings.notifications.show');
        Route::put('notifications', [NotificationPreferencesController::class, 'update'])->name('settings.notifications.update');
        Route::get('memory', [MemoryController::class, 'show'])->name('settings.memory.show');
        Route::get('agent-memory', [AgentMemoryController::class, 'index'])->name('settings.agent-memory.index');
        Route::get('company-memory', [CompanyMemoryController::class, 'index'])->name('settings.company-memory.index');
        Route::post('company-memory', [CompanyMemoryController::class, 'store'])->name('settings.company-memory.store');
        Route::put('company-memory/{fact}', [CompanyMemoryController::class, 'update'])->name('settings.company-memory.update');
        Route::delete('company-memory/{fact}', [CompanyMemoryController::class, 'destroy'])->name('settings.company-memory.destroy');
        Route::post('company-memory/{fact}/approve', [CompanyMemoryController::class, 'approve'])->name('settings.company-memory.approve');
        Route::post('company-memory/{fact}/reject', [CompanyMemoryController::class, 'reject'])->name('settings.company-memory.reject');
        Route::put('memory/preferences', [MemoryController::class, 'preference'])->name('settings.memory.preference');
        Route::post('memory/tell', [MemoryController::class, 'tell'])->middleware('throttle:30,1')->name('settings.memory.tell');
        Route::put('memory/{memory}', [MemoryController::class, 'update'])->name('settings.memory.update');
        Route::delete('memory/{memory}', [MemoryController::class, 'destroy'])->name('settings.memory.destroy');
        Route::delete('memory/agents/{agent}', [MemoryController::class, 'forget'])->name('settings.memory.forget');
        Route::put('password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('settings.password.update');
        Route::inertia('appearance', 'Settings/Appearance')->name('settings.appearance');
    });

    // The old addresses, kept for bookmarks and links already sent.
    Route::redirect('mailboxes', '/settings/mailboxes');
    Route::redirect('capabilities', '/settings/capabilities');
    Route::redirect('skills', '/settings/skills');
    Route::redirect('skills/create', '/settings/skills/create');
    Route::redirect('skills/{skill}/edit', '/settings/skills/{skill}/edit');
    Route::redirect('settings/erp', '/settings/integrations/erp');
    Route::redirect('workflows', '/settings/workflows');
    Route::redirect('workflows/new', '/settings/workflows/new');
    Route::redirect('workflows/{workflow}/edit', '/settings/workflows/{workflow}/edit');
    Route::redirect('routines', '/settings/routines');
    Route::redirect('runs', '/settings/runs');
    Route::redirect('runs/{run}', '/settings/runs/{run}');
});
