<?php

use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Resumo da manhã".

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setTimezone(config('app.timezone'))->setTime(8, 30));
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->chief] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Dona Amélia Sitoe']);
        $chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $owner->id]);

        // What needs her: an action to decide, an urgent email without an answer, a late task.
        Approval::factory()->create(['agent_id' => $chief->id, 'agent_run_id' => AgentRun::factory()->create(['agent_id' => $chief->id])->id]);
        $mailbox = Mailbox::factory()->personal()->create();
        MailboxOwner::factory()->create(['mailbox_id' => $mailbox->id, 'user_id' => $owner->id]);
        EmailMessage::factory()->create(['mailbox_id' => $mailbox->id, 'subject' => 'Proposta para a obra da EN7', 'priority' => 'urgent', 'from_name' => 'Carmélia Buque', 'received_at' => now()->subHours(3)]);
        EmailMessage::factory()->create(['mailbox_id' => $mailbox->id, 'subject' => 'Newsletter', 'priority' => 'low']);
        Task::factory()->create(['title' => 'Assinar a adenda', 'assignee_user_id' => $owner->id, 'due_at' => now()->subDays(2), 'status' => TaskStatus::Todo]);
        // What can wait, and the day.
        Task::factory()->create(['title' => 'Relatório de vendas', 'user_id' => $owner->id, 'status' => TaskStatus::InReview]);
        CalendarEvent::factory()->create(['calendar_connection_id' => CalendarConnection::factory()->create(['user_id' => $owner->id])->id, 'title' => 'Reunião com a SIR', 'starts_at' => now()->setTime(14, 0)]);

        return [$owner, $chief];
    });
});

it('shows the Chief of Staff\'s summary of the day on the entry screen until it is closed', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->where('morning.from.name', 'Chief of Staff')
            ->where('morning.greeting', 'Bom dia, Dona.')
            ->where('morning.needs.0.title', '1 acção à espera da sua decisão')
            ->where('morning.needs.1.kind', 'email')
            ->where('morning.needs.1.title', 'Carmélia Buque: «Proposta para a obra da EN7»')
            ->where('morning.needs.2.title', 'Assinar a adenda')
            ->has('morning.needs', 3)
            ->where('morning.can_wait.0.title', 'Relatório de vendas')
            ->where('morning.agenda.0.time', '14:00')
            ->where('morning.agenda.0.title', 'Reunião com a SIR'));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'morning-summary/close'))->assertRedirect();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))->assertInertia(fn (Assert $page) => $page->where('morning', null));

    $this->travel(1)->days();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))->assertInertia(fn (Assert $page) => $page->whereNot('morning', null));
});

it('drops an email from what needs her once it is answered', function () {
    asTenant($this->tenant, function () {
        $email = EmailMessage::query()->where('priority', 'urgent')->sole();
        $thread = EmailThread::factory()->create();
        $email->update(['thread_id' => $thread->id]);
        EmailMessage::factory()->create(['mailbox_id' => $email->mailbox_id, 'thread_id' => $thread->id, 'direction' => 'outbound', 'created_at' => now()]);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->has('morning.needs', 2)->where('morning.needs.1.title', 'Assinar a adenda'));
});
