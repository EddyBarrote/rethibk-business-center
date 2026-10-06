<?php

use App\Enums\Permission;
use App\Models\Briefing;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
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

    // "{Model}" in the path is the id of the row created above.
    $path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) asTenant($this->tenant, fn () => lastId('App\\Models\\'.$m[1])), $path);

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, $path))->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/', 'Home'],
    ['painel', 'Dashboard'],
    ['projects', 'Projects/Index'],
    ['agents', 'Agents/Index'],
    ['agents/{Agent}', 'Agents/Show'],
    ['runs', 'Runs/Index'],
    ['approvals', 'Approvals/Index'],
    ['knowledge', 'Knowledge/Index'],
    ['inbox', 'Inbox/Index'],
    ['inbox/{EmailMessage}', 'Inbox/Show'],
    ['notifications', 'Notifications/Index'],
    ['briefings', 'Briefings/Index'],
    ['briefings/{Briefing}', 'Briefings/Show'],
    ['reports', 'Reports/Index'],
    ['reports/{Report}', 'Reports/Show'],
    ['settings', 'Settings/Index'],
    ['settings/brand', 'Settings/Brand'],
    ['settings/departments', 'Settings/Departments/Index'],
    ['settings/users', 'Settings/Users/Index'],
    ['settings/roles', 'Settings/Roles'],
    ['settings/mailboxes', 'Mailboxes/Index'],
    ['settings/integrations', 'Settings/Integrations'],
    ['settings/integrations/erp', 'Settings/Erp'],
    ['settings/capabilities', 'Capabilities/Index'],
    ['settings/skills', 'Skills/Index'],
    ['settings/usage', 'Settings/Usage'],
    ['settings/profile', 'Settings/Profile'],
    ['settings/appearance', 'Settings/Appearance'],
]);

it('shows people their own mailboxes and the triage email routed to them, and triage email to whoever the matrix allows', function () {
    [$member, $mine, $routed, $triage] = asTenant($this->tenant, function () {
        $member = User::factory()->create(['role' => 'member']);
        $own = MailboxOwner::factory()->create(['user_id' => $member->id])->mailbox;
        $agentBox = Mailbox::factory()->create();

        return [
            $member,
            EmailMessage::factory()->create(['mailbox_id' => $own->id, 'subject' => 'Da minha caixa']),
            EmailMessage::factory()->create(['mailbox_id' => $agentBox->id, 'routed_to_user_id' => $member->id, 'subject' => 'Encaminhado para mim']),
            EmailMessage::factory()->create(['mailbox_id' => $agentBox->id, 'subject' => 'Só para a triagem']),
        ];
    });

    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, 'inbox'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('messages.data', fn ($rows) => collect($rows)->pluck('subject')->sort()->values()->all() === ['Da minha caixa', 'Encaminhado para mim']));
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, "inbox/{$mine->id}"))->assertOk();
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, "inbox/{$routed->id}"))->assertOk();
    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, "inbox/{$triage->id}"))->assertForbidden();

    $member->forceFill(['permission_overrides' => [Permission::ReadTriageEmails->value => true]])->save();
    $this->actingAs($member->fresh(), 'web')->get(tenantUrl($this->tenant, "inbox/{$triage->id}"))->assertOk();
});
