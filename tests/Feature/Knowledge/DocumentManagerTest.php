<?php

use App\Models\Agent;
use App\Models\DocumentFolder;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Gestor de ficheiros".

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->member, $this->agent] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(['name' => 'Barrote']),
        User::factory()->create(['name' => 'Naira']),
        Agent::factory()->create(['name' => 'Agente de Finanças']),
    ]);

    asTenant($this->tenant, function () {
        $folder = DocumentFolder::factory()->create(['name' => 'Propostas']);
        GeneratedDocument::factory()->create(['title' => 'Proposta Olankha', 'agent_id' => $this->agent->id, 'document_folder_id' => $folder->id, 'size_bytes' => 9000, 'format' => 'docx']);
        GeneratedDocument::factory()->create(['title' => 'Apuramento de IVA', 'agent_id' => $this->agent->id, 'size_bytes' => 2000, 'format' => 'xlsx']);
        GeneratedDocument::factory()->create(['title' => 'Ata da reunião', 'created_by_user_id' => $this->owner->id, 'size_bytes' => 500]);
        $this->folder = $folder;
    });
});

function documentTitles($test, string $query = ''): array
{
    return collect($test->actingAs($test->owner)->get(tenantUrl($test->tenant, 'documents'.$query))->viewData('page')['props']['documents']['data'])
        ->pluck('title')->all();
}

it('searches, filters by author and folder, and sorts', function () {
    expect(documentTitles($this, '?q=iva'))->toBe(['Apuramento de IVA'])
        ->and(documentTitles($this, '?autor=ia'))->toEqualCanonicalizing(['Proposta Olankha', 'Apuramento de IVA'])
        ->and(documentTitles($this, '?autor=pessoas'))->toBe(['Ata da reunião'])
        ->and(documentTitles($this, "?pasta={$this->folder->id}"))->toBe(['Proposta Olankha'])
        ->and(documentTitles($this, '?pasta=sem'))->toEqualCanonicalizing(['Apuramento de IVA', 'Ata da reunião'])
        ->and(documentTitles($this, '?ordem=nome'))->toBe(['Apuramento de IVA', 'Ata da reunião', 'Proposta Olankha'])
        ->and(documentTitles($this, '?ordem=tamanho'))->toBe(['Proposta Olankha', 'Apuramento de IVA', 'Ata da reunião']);
});

it('shows who made each file and how many files each folder has', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'documents?ordem=nome'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.data.0.agent.name', 'Agente de Finanças')
            ->has('documents.data.0.agent.avatar_url')
            ->where('documents.data.1.agent', null)
            ->where('documents.data.1.creator', 'Barrote')
            ->where('folders.0.name', 'Propostas')
            ->where('folders.0.count', 1)
            ->where('total', 3)
            ->where('unfiled', 2));
});

it('creates, renames and deletes folders, and moves files between them', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'documents/folders'), ['name' => 'Contratos'])->assertRedirect();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'documents/folders'), ['name' => 'Contratos'])->assertSessionHasErrors('name');

    $contracts = asTenant($this->tenant, fn () => DocumentFolder::query()->where('name', 'Contratos')->sole());
    $ata = asTenant($this->tenant, fn () => GeneratedDocument::query()->where('title', 'Ata da reunião')->sole());

    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "documents/{$ata->id}/folder"), ['folder_id' => $contracts->id])->assertRedirect();
    expect(asTenant($this->tenant, fn () => $ata->fresh()->document_folder_id))->toBe($contracts->id);

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "documents/folders/{$contracts->id}"), ['name' => 'Contratos 2026'])->assertRedirect();
    expect(asTenant($this->tenant, fn () => $contracts->fresh()->name))->toBe('Contratos 2026');

    // Deleting a folder keeps its files, at the top.
    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "documents/folders/{$contracts->id}"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => $ata->fresh()?->document_folder_id))->toBeNull();
});

it('does not let someone without the permission manage folders or move a file they cannot see', function () {
    $iva = asTenant($this->tenant, fn () => GeneratedDocument::query()->where('title', 'Apuramento de IVA')->sole());

    $this->actingAs($this->member)->patch(tenantUrl($this->tenant, "documents/{$iva->id}/folder"), ['folder_id' => $this->folder->id])->assertForbidden();
    expect(asTenant($this->tenant, fn () => $iva->fresh()->document_folder_id))->toBeNull();
});
