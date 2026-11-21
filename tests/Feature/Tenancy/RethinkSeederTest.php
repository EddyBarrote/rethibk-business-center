<?php

use App\Enums\CapabilitySource;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\Capability;
use App\Models\CompanyFact;
use App\Models\Department;
use App\Models\ErpConnection;
use App\Models\KnowledgeItem;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RethinkSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// The Rethink tenant as set up on 15–17.10.2026 (docs/DECISOES.md, "Seed do tenant Rethink").

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['services.rethink.erp_url' => null, 'services.rethink.erp_token' => null]);
});

it('creates the Rethink tenant with its people, agents, facts and Reactive articles', function () {
    $this->seed(RethinkSeeder::class);

    $tenant = Tenant::query()->where('slug', 'rethink')->firstOrFail();

    expect($tenant->settings['brand']['color'])->toBe('#0052CC')
        ->and($tenant->settings['brand']['font'])->toBe('Space Grotesk')
        ->and($tenant->settings)->not->toHaveKey('mail_domain');
    Storage::disk('local')->assertExists($tenant->settings['brand']['logo_path']);

    asTenant($tenant, function () {
        $owner = User::query()->where('email', 'ebarrote@rethink.co.mz')->firstOrFail();
        $general = Department::query()->where('slug', 'direccao-geral')->firstOrFail();

        expect($owner->role)->toBe(Role::Owner)
            ->and($owner->job_title)->toBe('CEO')
            ->and(User::query()->where('role', Role::Member)->pluck('email')->sort()->values()->all())->toBe(['kmacatange@rethink.co.mz', 'nairasitoe@rethink.co.mz'])
            ->and(Department::query()->count())->toBe(1)
            ->and(Agent::query()->count())->toBe(6)
            ->and(Agent::query()->where('department_id', $general->id)->where('reports_to_user_id', $owner->id)->count())->toBe(6)
            ->and(Agent::query()->where('key', 'finance')->value('description'))->toContain('só em leitura')
            ->and(Agent::query()->where('key', 'hr')->value('name'))->toBe('Director de Recursos Humanos')
            ->and(AgentRoutine::query()->whereIn('agent_id', Agent::query()->where('key', 'hr')->select('id'))->where('is_active', true)->count())->toBe(0)
            ->and(Capability::query()->where('key', 'comms.send_email')->value('is_enabled'))->toBeFalse()
            ->and(CompanyFact::query()->where('status', CompanyFact::PUBLISHED)->count())->toBe(22)
            ->and(KnowledgeItem::query()->where('status', 'published')->pluck('title')->sort()->values()->all())->toBe(['Reactive: preços e condições nas fontes', 'Reactive: serviços e o que incluem'])
            ->and(ErpConnection::query()->count())->toBe(0);
    });
});

it('fills in what is missing when run again, without duplicates', function () {
    $this->seed(RethinkSeeder::class);
    $tenant = Tenant::query()->where('slug', 'rethink')->firstOrFail();

    // What only exists later: an ERP write tool from a sync, and geral@ connected in Settings.
    $mailbox = asTenant($tenant, function () {
        Capability::query()->create(['key' => 'erp.create_invoice', 'name' => 'Criar factura', 'source' => CapabilitySource::Mcp, 'is_mutating' => true, 'is_enabled' => true]);

        return Mailbox::factory()->personal()->create(['address' => 'geral@rethink.co.mz']);
    });

    $this->seed(RethinkSeeder::class);

    expect(Tenant::query()->where('slug', 'rethink')->count())->toBe(1);

    asTenant($tenant, function () use ($mailbox) {
        expect(User::query()->count())->toBe(3)
            ->and(Agent::query()->count())->toBe(6)
            ->and(CompanyFact::query()->count())->toBe(22)
            ->and(KnowledgeItem::query()->count())->toBe(2)
            ->and(Capability::query()->where('key', 'erp.create_invoice')->value('is_enabled'))->toBeFalse()
            ->and(MailboxOwner::query()->where('mailbox_id', $mailbox->id)->count())->toBe(1)
            ->and(MailboxReader::query()->where('mailbox_id', $mailbox->id)->where('processes_new', true)->count())->toBe(1)
            ->and(MailboxReader::query()->where('mailbox_id', $mailbox->id)->count())->toBe(2);
    });
});
