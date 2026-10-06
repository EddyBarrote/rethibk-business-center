<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Attachments\AttachmentLimits;
use App\Attachments\AttachmentStore;
use App\Enums\ActorType;
use App\Enums\AttachmentKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\AgentPrompt;

// Anexos nas conversas (docs/DECISOES.md): people send images, PDF, documents
// and audio; the agent gets them as files when its model reads them, as text
// otherwise; agents send files back; triage reads email attachments.

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function wavBytes(): string
{
    $samples = str_repeat("\x00\x00", 1600);

    return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->other, $this->agent] = asTenant($this->tenant, fn () => [
        User::factory()->create(['name' => 'Chefe']),
        User::factory()->create(['name' => 'Outra']),
        Agent::factory()->create(['name' => 'Assistente', 'provider' => 'gemini']),
    ]);
    $this->agent->update(['reports_to_user_id' => $this->boss->id]);
});

/** Upload through the endpoints, in parts of $part bytes; returns the attachment id. */
function upload(object $test, string $name, string $bytes, array $target, int $part = 100): int
{
    $id = $test->actingAs($test->boss)->postJson(tenantUrl($test->tenant, 'files'), ['filename' => $name, 'size' => strlen($bytes), ...$target])
        ->assertCreated()->json('id');

    foreach (str_split($bytes, $part) as $index => $chunk) {
        $test->actingAs($test->boss)->post(tenantUrl($test->tenant, "files/{$id}/chunk"), [
            'offset' => $index * $part,
            'chunk' => UploadedFile::fake()->createWithContent('part', $chunk),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    return $id;
}

it('uploads a file in parts, checks its bytes, and sends it with the first message to an agent', function () {
    $id = upload($this, 'fatura.pdf', pdfBytes(), ['agent_id' => $this->agent->id], 20);

    $attachment = asTenant($this->tenant, fn () => MessageAttachment::query()->findOrFail($id));
    expect($attachment->isReady())->toBeTrue()
        ->and($attachment->kind)->toBe(AttachmentKind::Pdf)
        ->and($attachment->mime_type)->toBe('application/pdf');

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['attachment_ids' => [$id]])->assertRedirect();

    asTenant($this->tenant, function () use ($id) {
        $message = Task::query()->sole()->messages()->sole();
        expect($message->body)->toBe('')
            ->and($message->attachments->pluck('id')->all())->toBe([$id])
            ->and(AgentRun::query()->sole()->input)->toContain('(enviou só anexos)');
    });
});

it('refuses types the company does not accept and files whose bytes are not what the name says', function () {
    $this->actingAs($this->boss)->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'programa.exe', 'size' => 10, 'agent_id' => $this->agent->id])
        ->assertUnprocessable();

    asTenant($this->tenant, fn () => $this->tenant->update(['settings' => ['attachments' => ['types' => ['pdf'], 'max_mb' => 1]]]));
    $this->actingAs($this->boss)->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'foto.png', 'size' => 10, 'agent_id' => $this->agent->id])
        ->assertUnprocessable();
    $this->actingAs($this->boss)->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'grande.pdf', 'size' => 2 * 1024 * 1024, 'agent_id' => $this->agent->id])
        ->assertUnprocessable();

    $id = $this->actingAs($this->boss)->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'falso.pdf', 'size' => 11, 'agent_id' => $this->agent->id])->json('id');
    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "files/{$id}/chunk"), [
        'offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect(asTenant($this->tenant, fn () => MessageAttachment::query()->count()))->toBe(0);
});

it('gives Gemini images, PDF and audio as files, and the thread history the latest files again', function () {
    GenericAgent::fake(['Vejo a factura e ouvi a nota.', 'Sim.']);

    $png = upload($this, 'foto.png', base64_decode(PNG), ['agent_id' => $this->agent->id]);
    $wav = upload($this, 'gravacao-10h00.wav', wavBytes(), ['agent_id' => $this->agent->id], 1000);
    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Veja isto', 'attachment_ids' => [$png, $wav]]);

    asTenant($this->tenant, function () {
        app(AgentRunner::class)->run(AgentRun::query()->sole());

        GenericAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 2
            && $prompt->attachments[0] instanceof Image
            && $prompt->attachments[1] instanceof Audio
            && $prompt->attachments[1]->mimeType() === 'audio/wav'
            && $prompt->contains('segue em ficheiro'));

        $task = Task::query()->sole();
        app(TaskThread::class)->post($task, $this->boss, 'E agora?');
        $history = app(TaskThread::class)->history(AgentRun::query()->latest('id')->first());
        expect($history[0]->attachments)->toHaveCount(2);
    });
});

it('gives a model that cannot read the file its text instead', function () {
    GenericAgent::fake(['Li o texto.']);
    $this->agent->update(['provider' => 'deepseek']);

    $id = upload($this, 'nota.txt', 'Encomenda de 40 sacos de cimento para a Beira.', ['agent_id' => $this->agent->id]);
    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Resume', 'attachment_ids' => [$id]]);

    asTenant($this->tenant, function () use ($id) {
        app(AgentRunner::class)->run(AgentRun::query()->sole());

        GenericAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->isEmpty() && $prompt->contains('40 sacos de cimento'));
        expect(MessageAttachment::query()->find($id)->text_status)->toBe('done');
    });
});

it('lets only who may read the conversation open its files', function () {
    $id = upload($this, 'foto.png', base64_decode(PNG), ['agent_id' => $this->agent->id]);

    // Before it is sent, only its uploader.
    $this->actingAs($this->other)->get(tenantUrl($this->tenant, "files/{$id}"))->assertNotFound();
    $this->actingAs($this->other)->delete(tenantUrl($this->tenant, "files/{$id}"))->assertNotFound();

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Olhe', 'attachment_ids' => [$id]]);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "files/{$id}"))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($this->other)->get(tenantUrl($this->tenant, "files/{$id}"))->assertForbidden();

    // Someone else's upload cannot be claimed into a message.
    $mine = upload($this, 'b.png', base64_decode(PNG), ['agent_id' => $this->agent->id]);
    asTenant($this->tenant, fn () => $this->agent->assignees()->attach($this->other->id));
    $this->actingAs($this->other)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'x', 'attachment_ids' => [$mine]])
        ->assertSessionHasErrors('attachment_ids');
});

it('sends the documents an agent generates, and files of the knowledge base, with its reply', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $task = $threads->open(['kind' => TaskKind::Chat, 'title' => 'Relatório', 'status' => TaskStatus::InProgress, 'assignee_agent_id' => $this->agent->id, 'user_id' => $this->boss->id], $this->boss, 'Faça o relatório');
        $run = AgentRun::query()->sole();

        Storage::disk('local')->put('documents/rel.pdf', pdfBytes());
        GeneratedDocument::factory()->create(['agent_id' => $this->agent->id, 'agent_run_id' => $run->id, 'path' => 'documents/rel.pdf', 'filename' => 'relatorio.pdf']);
        GenericAgent::fake(['Segue o relatório.']);

        app(AgentRunner::class)->run($run);

        $reply = $task->messages()->where('author_type', ActorType::Agent)->sole();
        expect($reply->attachments->pluck('filename')->all())->toBe(['relatorio.pdf'])
            ->and($reply->attachments->first()->source)->toBe('generated');

        // files.read reads files of this thread only.
        $read = app(CapabilityRegistry::class)->find('files.read')->execute(['attachment_id' => $reply->attachments->first()->id], new CapabilityContext($this->agent, $run));
        expect($read->ok)->toBeTrue();
    });

    // The conversation previews it with the Markdown the agent wrote, for who may read the conversation.
    $file = asTenant($this->tenant, fn () => MessageAttachment::query()->where('source', 'generated')->sole());
    $this->actingAs($this->boss)->getJson(tenantUrl($this->tenant, "files/{$file->id}/preview"))
        ->assertOk()->assertJson(['format' => 'markdown', 'text' => "# Relatório\n\nTexto."]);
    $this->actingAs($this->other)->getJson(tenantUrl($this->tenant, "files/{$file->id}/preview"))->assertForbidden();

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'tasks/'.asTenant($this->tenant, fn () => Task::query()->value('id'))))
        ->assertInertia(fn (Assert $page) => $page->component('Tasks/Show')
            ->where('messages.1.attachments.0.name', 'relatorio.pdf')
            ->where('attachment_limits.max_files', 10));
});

it('gives the triage agent the email attachments as files', function () {
    GenericAgent::fake(['Triado.']);

    asTenant($this->tenant, function () {
        $email = EmailMessage::factory()->create();
        Storage::disk('local')->put('mail/a/foto.png', base64_decode(PNG));
        Storage::disk('local')->put('mail/a/pedido.pdf', pdfBytes());
        EmailAttachment::factory()->create(['email_message_id' => $email->id, 'filename' => 'foto.png', 'mime_type' => 'image/png', 'path' => 'mail/a/foto.png', 'extracted_text' => null, 'ocr_status' => 'skipped']);
        EmailAttachment::factory()->create(['email_message_id' => $email->id, 'filename' => 'pedido.pdf', 'path' => 'mail/a/pedido.pdf']);
        EmailAttachment::factory()->create(['email_message_id' => $email->id, 'filename' => 'enorme.pdf', 'path' => 'mail/a/x.pdf', 'ocr_status' => 'discarded']);

        $run = app(AgentRunner::class)->create($this->agent, 'Faz a triagem', TriggerType::Email, source: $email);
        app(AgentRunner::class)->run($run);

        GenericAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 2
            && $prompt->contains('anexo de email')
            && $prompt->contains('ficheiro indisponível'));
    });
});

it('saves the company limits in Definições › Anexos and deletes files never sent', function () {
    $owner = asTenant($this->tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner)->get(tenantUrl($this->tenant, 'settings/attachments'))
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Attachments')->where('settings.max_mb', 20));
    $this->actingAs($owner)->put(tenantUrl($this->tenant, 'settings/attachments'), ['max_mb' => 5, 'max_files' => 3, 'audio_max_minutes' => 2, 'types' => ['image', 'audio']])
        ->assertRedirect();
    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'settings/attachments'))->assertForbidden();

    asTenant($this->tenant->fresh(), function () {
        $limits = AttachmentLimits::current();
        expect($limits->maxMb)->toBe(5)->and($limits->types)->toBe(['image', 'audio'])->and($limits->extensions())->toContain('wav')->not->toContain('pdf');

        $old = MessageAttachment::factory()->create(['task_id' => null, 'created_at' => now()->subDays(2)]);
        $sent = MessageAttachment::factory()->create(['created_at' => now()->subDays(2), 'task_message_id' => TaskMessage::factory()->create()->id]);
        expect(app(AttachmentStore::class)->prune())->toBe(1)
            ->and(MessageAttachment::query()->find($old->id))->toBeNull()
            ->and(MessageAttachment::query()->find($sent->id))->not->toBeNull();
    });
});
