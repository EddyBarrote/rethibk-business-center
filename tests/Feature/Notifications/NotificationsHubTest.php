<?php

use App\Ai\Runs\ApprovalService;
use App\Enums\NotificationCategory;
use App\Mail\NoticeMail;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\Mail;

// The notifications centre (docs/DECISOES.md, "Centro de notificações").

beforeEach(function () {
    Mail::fake();
    $this->tenant = Tenant::factory()->create();
    [$this->person, $this->other] = asTenant($this->tenant, fn () => [
        User::factory()->create(['role' => 'member', 'name' => 'Ana']),
        User::factory()->create(['role' => 'member']),
    ]);
});

function notifyAs(Tenant $tenant, User $user, string $title, NotificationCategory $category, array $extra = []): void
{
    asTenant($tenant, fn () => app(Notifier::class)->notify($user, $title, 'Corpo', $extra['url'] ?? null, 'Agente X', 'info', $category, $extra['meta'] ?? []));
}

it('keeps the kind of each notification and filters the centre by kind and by unread', function () {
    notifyAs($this->tenant, $this->person, 'Nova tarefa', NotificationCategory::Task, ['url' => '/tasks/1']);
    notifyAs($this->tenant, $this->person, 'Prazo a chegar', NotificationCategory::Deadline);
    notifyAs($this->tenant, $this->person, 'Agente respondeu', NotificationCategory::Conversation);
    notifyAs($this->tenant, $this->other, 'De outra pessoa', NotificationCategory::Task);
    $this->person->notifications()->where('type', 'deadline')->update(['read_at' => now()]);

    expect($this->person->notifications()->pluck('type')->sort()->values()->all())->toBe(['conversation', 'deadline', 'task']);

    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'notifications'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Notifications/Index')
            ->has('notifications.data', 3)
            ->where('categories', fn ($categories) => collect($categories)->firstWhere('key', 'task')['unread'] === 1
                && collect($categories)->firstWhere('key', 'deadline')['total'] === 1
                && collect($categories)->firstWhere('key', 'deadline')['unread'] === 0));

    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'notifications?tipo=task'))
        ->assertInertia(fn ($page) => $page->where('notifications.data', fn ($rows) => collect($rows)->pluck('title')->all() === ['Nova tarefa']));
    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'notifications?estado=por-ler'))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 2)->where('filters.estado', 'por-ler'));
    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'notifications?tipo=nada'))->assertSessionHasErrors('tipo');
});

it('marks chosen ones, one kind or all as read, and back to unread', function () {
    notifyAs($this->tenant, $this->person, 'Um', NotificationCategory::Task);
    notifyAs($this->tenant, $this->person, 'Dois', NotificationCategory::Email);
    notifyAs($this->tenant, $this->person, 'Três', NotificationCategory::Email);
    notifyAs($this->tenant, $this->other, 'Alheia', NotificationCategory::Task);
    $one = $this->person->notifications()->where('type', 'task')->value('id');
    $alien = $this->other->notifications()->value('id');

    $this->actingAs($this->person, 'web')->post(tenantUrl($this->tenant, 'notifications/read'), ['ids' => [$one, $alien]])->assertRedirect();
    expect($this->person->unreadNotifications()->count())->toBe(2)
        ->and($this->other->unreadNotifications()->count())->toBe(1);

    $this->actingAs($this->person, 'web')->post(tenantUrl($this->tenant, 'notifications/read'), ['tipo' => 'email']);
    expect($this->person->unreadNotifications()->count())->toBe(0);

    $this->actingAs($this->person, 'web')->post(tenantUrl($this->tenant, 'notifications/unread'), ['ids' => [$one]]);
    expect($this->person->unreadNotifications()->pluck('id')->all())->toBe([$one]);

    $this->actingAs($this->person, 'web')->post(tenantUrl($this->tenant, 'notifications/read'));
    expect($this->person->unreadNotifications()->count())->toBe(0);
});

it('gives the bell the latest ones and the unread count', function () {
    foreach (range(1, 10) as $i) {
        notifyAs($this->tenant, $this->person, "Aviso {$i}", NotificationCategory::Agent);
    }

    $this->actingAs($this->person, 'web')->getJson(tenantUrl($this->tenant, 'notifications/recent'))->assertOk()
        ->assertJsonCount(8, 'notifications')
        ->assertJsonPath('unread', 10)
        ->assertJsonPath('notifications.0.category', 'agent');
});

it('opens a run in its conversation with the run in the side panel', function () {
    [$task, $run] = asTenant($this->tenant, function () {
        $task = Task::factory()->create(['user_id' => $this->person->id]);

        return [$task, AgentRun::factory()->create(['task_id' => $task->id])];
    });
    notifyAs($this->tenant, $this->person, 'Do agente', NotificationCategory::Agent, ['url' => "/runs/{$run->id}"]);
    notifyAs($this->tenant, $this->person, 'Respondeu', NotificationCategory::Conversation, ['url' => "/tasks/{$task->id}", 'meta' => ['task_id' => $task->id, 'run_id' => $run->id]]);
    [$fromRun, $reply] = [
        $this->person->notifications()->where('type', 'agent')->value('id'),
        $this->person->notifications()->where('type', 'conversation')->value('id'),
    ];

    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, "notifications/{$fromRun}"))->assertRedirect("/tasks/{$task->id}?execucao={$run->id}");
    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, "notifications/{$reply}"))->assertRedirect("/tasks/{$task->id}?execucao={$run->id}");
    expect($this->person->unreadNotifications()->count())->toBe(0);
});

it('puts an approval that needs a person in the centre of whoever may decide it, to approve right there', function () {
    $approval = asTenant($this->tenant, function () {
        $agent = Agent::factory()->create(['reports_to_user_id' => $this->person->id, 'name' => 'Agente de Finanças']);
        $approval = Approval::factory()->create(['agent_id' => $agent->id, 'action_summary' => 'Pagar 50 000 MZN ao fornecedor']);

        return app(ApprovalService::class)->escalate($approval, null, 'Pagamento: decide uma pessoa.');
    });

    $notice = $this->person->notifications()->sole();
    expect($notice->type)->toBe('approval')
        ->and($notice->data['approval_id'])->toBe($approval->id)
        ->and($notice->data['body'])->toContain('Pagar 50 000 MZN')
        ->and($this->other->notifications()->count())->toBe(0);
    // Approvals reach people by email too, by default.
    Mail::assertSent(NoticeMail::class, fn (NoticeMail $mail) => $mail->hasTo($this->person->email) && str_contains((string) $mail->link, "/notifications/{$notice->id}"));

    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'notifications'))
        ->assertInertia(fn ($page) => $page->where('awaiting_decision', 1)
            ->where('notifications.data.0.approval.can_decide', true)
            ->where('notifications.data.0.approval.status', 'pending'));

    $this->actingAs($this->other, 'web')->get(tenantUrl($this->tenant, 'notifications'))
        ->assertInertia(fn ($page) => $page->where('awaiting_decision', 0));
});

it('follows each person\'s preferences, and never lets approvals leave the centre', function () {
    $this->actingAs($this->person, 'web')->put(tenantUrl($this->tenant, 'settings/notifications'), ['category' => 'deadline', 'channel' => 'app', 'enabled' => false])->assertRedirect();
    $this->actingAs($this->person, 'web')->put(tenantUrl($this->tenant, 'settings/notifications'), ['category' => 'deadline', 'channel' => 'email', 'enabled' => true]);
    $this->actingAs($this->person, 'web')->put(tenantUrl($this->tenant, 'settings/notifications'), ['category' => 'approval', 'channel' => 'app', 'enabled' => false])
        ->assertSessionHas('error');

    notifyAs($this->tenant, $this->person, 'Contrato a terminar', NotificationCategory::Deadline);
    notifyAs($this->tenant, $this->person, 'Nova tarefa', NotificationCategory::Task);

    expect($this->person->notifications()->pluck('type')->all())->toBe(['task']);
    Mail::assertSent(NoticeMail::class, 1);
    Mail::assertSent(NoticeMail::class, fn (NoticeMail $mail) => $mail->title === 'Contrato a terminar' && $mail->link === null);

    $this->actingAs($this->person, 'web')->get(tenantUrl($this->tenant, 'settings/notifications'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Notifications')
            ->where('categories', fn ($rows) => collect($rows)->firstWhere('key', 'deadline')['prefs'] === ['app' => false, 'toast' => false, 'email' => true]
                && collect($rows)->firstWhere('key', 'approval')['prefs']['app'] === true));
});

it('tells the kind of older notifications from their link and title', function () {
    expect(NotificationCategory::infer(['url' => '/tasks/3', 'title' => 'Nova tarefa TSK-3: x']))->toBe(NotificationCategory::Task)
        ->and(NotificationCategory::infer(['url' => '/tasks/3', 'title' => 'Agente respondeu']))->toBe(NotificationCategory::Conversation)
        ->and(NotificationCategory::infer(['url' => '/tasks/3', 'title' => 'TSK-3 pronta para rever']))->toBe(NotificationCategory::Waiting)
        ->and(NotificationCategory::infer(['url' => '/inbox/9', 'title' => 'Prazo a chegar: proposta']))->toBe(NotificationCategory::Deadline)
        ->and(NotificationCategory::infer(['url' => '/inbox/9', 'title' => 'Pedido de cliente fora do SLA']))->toBe(NotificationCategory::Email)
        ->and(NotificationCategory::infer(['url' => '/knowledge/2', 'title' => 'Para rever: x']))->toBe(NotificationCategory::Document)
        ->and(NotificationCategory::infer(['url' => null, 'title' => 'Olá']))->toBe(NotificationCategory::Agent);
});
