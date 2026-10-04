<?php

use App\Models\Briefing;
use App\Models\Contract;
use App\Models\Department;
use App\Models\EmailMessage;
use App\Models\PurchaseRequest;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;

// Every screen of the tenant console renders with data in it, and members
// only see what is theirs.

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        templateAgent('triage');
        templateAgent('chief_of_staff');
        Briefing::factory()->create(['for_user_id' => $this->owner->id]);
        Report::factory()->create();
        Contract::factory()->create();
        PurchaseRequest::factory()->create(['requested_by_user_id' => $this->owner->id]);
        Tender::factory()->create();
        EmailMessage::factory()->create();
    });
});

afterEach(fn () => @unlink($this->store));

it('renders every console page for the owner', function (string $path, string $component) {
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, $path))->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/', 'Dashboard'],
    ['agents', 'Agents/Index'],
    ['agents/1', 'Agents/Show'],
    ['runs', 'Runs/Index'],
    ['approvals', 'Approvals/Index'],
    ['knowledge', 'Knowledge/Index'],
    ['inbox', 'Inbox/Index'],
    ['inbox/1', 'Inbox/Show'],
    ['tenders', 'Tenders/Index'],
    ['notifications', 'Notifications/Index'],
    ['briefings', 'Briefings/Index'],
    ['briefings/1', 'Briefings/Show'],
    ['reports', 'Reports/Index'],
    ['reports/1', 'Reports/Show'],
    ['finance', 'Finance/Index'],
    ['procurement', 'Procurement/Index'],
    ['procurement/1', 'Procurement/Show'],
    ['contracts', 'Contracts/Index'],
    ['contracts/1', 'Contracts/Show'],
    ['clients', 'Clients/Index'],
    ['clients/ACC-0001', 'Clients/Show'],
    ['settings/erp', 'Settings/Erp'],
]);

it('shows members only the emails routed to them or their department', function () {
    [$member, $mine, $theirs] = asTenant($this->tenant, function () {
        $works = Department::factory()->create();
        $member = User::factory()->create(['role' => 'member', 'department_id' => $works->id]);

        return [
            $member,
            EmailMessage::factory()->create(['department_id' => $works->id, 'subject' => 'Para as obras']),
            EmailMessage::factory()->create(['subject' => 'Só para a direcção']),
        ];
    });

    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, 'inbox'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('messages.data', fn ($rows) => collect($rows)->pluck('subject')->all() === ['Para as obras']));
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, "inbox/{$mine->id}"))->assertOk();
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, "inbox/{$theirs->id}"))->assertForbidden();
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, 'finance'))->assertForbidden();
});
