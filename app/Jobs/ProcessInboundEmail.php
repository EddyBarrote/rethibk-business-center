<?php

namespace App\Jobs;

use App\Ai\Runs\AgentRunner;
use App\Email\EmailPrompt;
use App\Email\PromptInjectionDetector;
use App\Enums\EmailStatus;
use App\Enums\TriggerType;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Support\TextExtractor;
use App\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\Storage;

/**
 * Section 9.2, steps 6 and 7: read the attachments (text and OCR), flag
 * prompt injection, and hand the email to the agent that owns the mailbox.
 */
class ProcessInboundEmail extends TenantAwareJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(int $tenantId, public int $messageId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(TextExtractor $extractor, PromptInjectionDetector $detector, AgentRunner $runner): void
    {
        $message = EmailMessage::query()->with(['mailbox.agent', 'attachments'])->find($this->messageId);

        if ($message === null || $message->status !== EmailStatus::Received) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            $this->extract($attachment, $extractor);
        }

        $flags = $message->flags ?? [];

        if ($detector->detect((string) $message->subject, $message->plainText(), ...$message->attachments->pluck('extracted_text')->filter()->all())) {
            $flags[] = 'prompt_injection';
        }

        $message->forceFill(['flags' => array_values(array_unique($flags)) ?: null])->save();

        $agent = $message->mailbox->processor();

        if ($agent === null || ! $agent->isActive()) {
            // Kept for people to read; nobody to triage it automatically.
            $message->forceFill(['status' => EmailStatus::Processed])->save();

            return;
        }

        $message->load('thread');
        $message->forceFill(['status' => EmailStatus::Processing])->save();

        // On a sync queue the run finishes inside dispatch() and moves the
        // email on, so only the run id is written afterwards.
        $prompt = $message->mailbox->isPersonal() ? EmailPrompt::forPerson($message) : EmailPrompt::for($message);
        $run = $runner->dispatch($agent, $prompt, TriggerType::Email, source: $message);
        EmailMessage::query()->whereKey($message->id)->update(['agent_run_id' => $run->id]);
    }

    private function extract(EmailAttachment $attachment, TextExtractor $extractor): void
    {
        if ($attachment->ocr_status !== 'pending' || $attachment->path === null) {
            return;
        }

        $disk = Storage::disk($attachment->disk ?? (string) config('mail_ingest.disk'));

        if (! $disk->exists($attachment->path)) {
            $attachment->forceFill(['ocr_status' => 'failed'])->save();

            return;
        }

        // Works for any disk: the extractor reads from a local temporary copy.
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, (string) $disk->get($attachment->path));

        try {
            $text = $extractor->extract($tmp, $attachment->mime_type, $attachment->filename);
        } finally {
            @unlink($tmp);
        }

        $attachment->forceFill([
            'extracted_text' => $text,
            'ocr_status' => $text === null ? 'skipped' : 'done',
        ])->save();
    }
}
