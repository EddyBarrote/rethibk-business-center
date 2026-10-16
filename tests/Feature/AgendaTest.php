<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Agenda".

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-16 09:00:00');
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->naira, $this->finance, $this->email] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Barrote']);
        $naira = User::factory()->create(['name' => 'Naira']);
        $finance = Agent::factory()->create(['name' => 'Agente de Finanças', 'reports_to_user_id' => $owner->id]);
        $email = EmailMessage::factory()->create(['subject' => 'TVCabo: fim da subscrição', 'sent_to_triage_at' => now()]);

        return [$owner, $naira, $finance, $email];
    });
});

afterEach(fn () => Carbon::setTestNow());

function scheduleAs(Agent $agent, array $arguments, ?Task $task = null): array
{
    $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual, taskId: $task?->id);

    return app(CapabilityRegistry::class)->find('followups.schedule')->execute($arguments, new CapabilityContext($agent, $run))->data;
}

it('does not schedule a second reminder for what a colleague already follows that day', function () {
    asTenant($this->tenant, function () {
        $task = Task::factory()->create([
            'title' => 'Pagar a TVCabo', 'assignee_user_id' => $this->naira->id,
            'due_at' => Carbon::parse('2026-10-19 00:00', 'Africa/Maputo')->utc(),
            'source_type' => $this->email->getMorphClass(), 'source_id' => $this->email->id,
        ]);

        $result = scheduleAs($this->finance, ['title' => 'Renovar a TVCabo', 'due' => '2026-10-19', 'email_id' => $this->email->id]);

        expect($result['scheduled'])->toBeFalse()
            ->and($result['already_tracked'])->toContain("«{$task->title}»")->toContain('Naira')
            ->and(FollowUp::query()->count())->toBe(0);

        // Two days before is a reminder of its own, and it belongs to that task.
        $result = scheduleAs($this->finance, ['title' => 'Lembrar a Naira da TVCabo', 'due' => '2026-10-17 09:00', 'email_id' => $this->email->id]);

        expect($result['scheduled'])->toBeTrue()->and($result['task'])->toBe($task->title)
            ->and(FollowUp::query()->sole()->task_id)->toBe($task->id);

        // And the same again that day is a duplicate.
        expect(scheduleAs($this->finance, ['title' => 'Outra vez', 'due' => '2026-10-17 15:00', 'task_id' => $task->id])['scheduled'])->toBeFalse();
    });
});

it('keeps a reminder from a conversation for the person in it, not for the boss', function () {
    asTenant($this->tenant, function () {
        $chat = Task::factory()->chat()->create(['user_id' => $this->naira->id, 'chat_key' => 'agent:'.$this->finance->id.':user:'.$this->naira->id, 'assignee_agent_id' => $this->finance->id]);

        scheduleAs($this->finance, ['title' => 'Ligar ao banco', 'due' => '2026-10-20'], $chat);

        expect(FollowUp::query()->sole())->user_id->toBe($this->naira->id)->task_id->toBeNull();
    });
});

it('shows reminders, deadlines and routines on the calendar, each to whom it concerns', function () {
    asTenant($this->tenant, function () {
        FollowUp::factory()->create(['agent_id' => $this->finance->id, 'title' => 'Seguimento do agente', 'due_at' => now()->addDay()]);
        FollowUp::factory()->create(['user_id' => $this->naira->id, 'title' => 'Lembrete da Naira', 'due_at' => now()->addDay()]);
        Task::factory()->create(['title' => 'Prazo da Naira', 'assignee_user_id' => $this->naira->id, 'due_at' => now()->addDays(2)]);
        Task::factory()->create(['title' => 'Prazo do agente', 'assignee_agent_id' => $this->finance->id, 'due_at' => now()->addDays(3)]);
    });

    $titles = fn (User $user, string $query = '') => collect($this->actingAs($user)->get(tenantUrl($this->tenant, 'agenda'.$query))
        ->assertOk()->viewData('page')['props']['events'])->pluck('title')->all();

    // The boss: their agent's work. Not Naira's.
    expect($titles($this->owner))->toEqualCanonicalizing(['Seguimento do agente', 'Prazo do agente']);
    // The whole company: Naira's deadline too, never her own reminder.
    expect($titles($this->owner, '?quem=todos'))->toEqualCanonicalizing(['Seguimento do agente', 'Prazo do agente', 'Prazo da Naira']);
    // Naira: her things.
    expect($titles($this->naira))->toEqualCanonicalizing(['Lembrete da Naira', 'Prazo da Naira']);
    // Without the permission, «todos» is ignored.
    expect($titles($this->naira, '?quem=todos'))->toEqualCanonicalizing(['Lembrete da Naira', 'Prazo da Naira']);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'agenda?vista=semana&data=2026-10-16'))
        ->assertInertia(fn (Assert $page) => $page->component('Agenda/Index')->where('from', '2026-10-12')->where('to', '2026-10-18'));
});

it('lets a person add, finish, move and delete their own reminders', function () {
    $this->actingAs($this->naira)->post(tenantUrl($this->tenant, 'agenda/follow-ups'), ['title' => 'Ligar à TVCabo', 'date' => '2026-10-18', 'time' => '10:30'])->assertRedirect();

    $followUp = asTenant($this->tenant, fn () => FollowUp::query()->sole());
    expect($followUp->user_id)->toBe($this->naira->id)
        ->and($followUp->due_at->toIso8601String())->toBe(Carbon::parse('2026-10-18 10:30', 'Africa/Maputo')->utc()->toIso8601String());

    // Someone else's: no.
    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"), ['done' => true])->assertForbidden();

    $this->actingAs($this->naira)->patch(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"), ['done' => true])->assertRedirect();
    expect($followUp->fresh()->done_at)->not->toBeNull();

    // Moved, it is due and announced again.
    asTenant($this->tenant, fn () => $followUp->fresh()->forceFill(['notified_at' => now()])->save());
    $this->actingAs($this->naira)->patch(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"), ['date' => '2026-10-21'])->assertRedirect();
    expect($followUp->fresh())->done_at->toBeNull()->notified_at->toBeNull();

    $this->actingAs($this->naira)->delete(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => FollowUp::query()->count()))->toBe(0);
});

it('lets the boss close what their agent keeps for itself', function () {
    $followUp = asTenant($this->tenant, fn () => FollowUp::factory()->create(['agent_id' => $this->finance->id, 'due_at' => now()->addDay()]));

    $this->actingAs($this->naira)->patch(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"), ['done' => true])->assertForbidden();
    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"), ['done' => true])->assertRedirect();
    // An agent's reminder is not deleted from the Agenda, only closed.
    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "agenda/follow-ups/{$followUp->id}"))->assertForbidden();
});

it('gives a private calendar link that Google or Outlook can read, until it is turned off', function () {
    asTenant($this->tenant, fn () => Task::factory()->create(['title' => 'Pagar a TVCabo', 'assignee_user_id' => $this->naira->id, 'due_at' => Carbon::parse('2026-10-19 00:00', 'Africa/Maputo')->utc()]));

    $response = $this->actingAs($this->naira)->post(tenantUrl($this->tenant, 'agenda/feed'))->assertRedirect();
    $url = $response->getSession()->get('calendar_feed_url');
    expect($url)->toContain('/calendar/')->toEndWith('.ics')
        ->and($this->naira->fresh()->calendar_token)->not->toBeNull()->not->toBe(basename($url, '.ics'));

    $this->app['auth']->forgetGuards();
    $feed = $this->get(tenantUrl($this->tenant, parse_url($url, PHP_URL_PATH)))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($feed->getContent())->toContain('BEGIN:VCALENDAR')->toContain('SUMMARY:Pagar a TVCabo')->toContain('DTSTART;VALUE=DATE:20261019');

    $this->actingAs($this->naira)->delete(tenantUrl($this->tenant, 'agenda/feed'))->assertRedirect();
    $this->app['auth']->forgetGuards();
    $this->get(tenantUrl($this->tenant, parse_url($url, PHP_URL_PATH)))->assertNotFound();
});

it('filters by kind, by who handles it and by state', function () {
    asTenant($this->tenant, function () {
        FollowUp::factory()->create(['agent_id' => $this->finance->id, 'title' => 'Seguimento atrasado', 'due_at' => now()->subHours(2)]);
        FollowUp::factory()->create(['agent_id' => $this->finance->id, 'title' => 'Seguimento feito', 'due_at' => now()->addDay(), 'done_at' => now()]);
        Task::factory()->create(['title' => 'Prazo do agente', 'assignee_agent_id' => $this->finance->id, 'due_at' => now()->addDays(3)]);
        Task::factory()->create(['title' => 'Prazo da Naira', 'assignee_user_id' => $this->naira->id, 'due_at' => now()->addDays(2)]);
    });

    $page = fn (string $query) => $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'agenda?quem=todos&'.$query))->assertOk()->viewData('page')['props'];
    $titles = fn (string $query) => collect($page($query)['events'])->pluck('title')->all();

    expect($titles('tipo=prazos'))->toEqualCanonicalizing(['Prazo do agente', 'Prazo da Naira'])
        ->and($titles('pessoa=user-'.$this->naira->id))->toBe(['Prazo da Naira'])
        ->and($titles('estado=em-atraso'))->toBe(['Seguimento atrasado'])
        ->and($titles('estado=feitos'))->toBe(['Seguimento feito'])
        ->and($titles('estado=por-fazer&tipo=seguimentos'))->toBe(['Seguimento atrasado'])
        // Unknown values are ignored.
        ->and($titles('tipo=x&pessoa=1;drop'))->toHaveCount(4);

    // Who to filter by comes from the whole period, people first, whatever is filtered.
    expect(collect($page('tipo=prazos&pessoa=user-'.$this->naira->id)['people'])->pluck('name')->all())->toBe(['Naira', 'Agente de Finanças']);
});
