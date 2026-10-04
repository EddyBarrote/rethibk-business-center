<?php

namespace App\Console\Commands\Watch;

use App\Ai\Runs\AgentRunner;
use App\Enums\TriggerType;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\PurchaseRequest;
use App\Models\Tender;
use App\Models\User;
use App\Support\Notifier;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A follow-up for a person becomes a notification; one an agent scheduled
 * for itself wakes the agent up.
 */
#[Signature('followups:notify')]
#[Description('Avisa dos seguimentos que venceram (a cada 5 minutos)')]
class NotifyFollowUps extends Command
{
    public function handle(TenantManager $tenants, Notifier $notifier, AgentRunner $runner): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($notifier, $runner, &$count): void {
            FollowUp::query()->whereNull('done_at')->whereNull('notified_at')->where('due_at', '<=', now())->with(['user', 'agent.reportsTo'])
                ->each(function (FollowUp $followUp) use ($notifier, $runner, &$count): void {
                    $followUp->forceFill(['notified_at' => now()])->save();
                    $count++;

                    $person = $followUp->user ?? $followUp->agent?->reportsTo;

                    if ($person instanceof User) {
                        $notifier->notify($person, 'Seguimento: '.$followUp->title, (string) ($followUp->note ?: 'Chegou a data deste seguimento.'), $this->link($followUp), $followUp->agent?->name, 'warning');
                    }

                    if ($followUp->user_id === null && $followUp->agent?->isActive()) {
                        $runner->dispatch($followUp->agent, "Venceu o seguimento #{$followUp->id} «{$followUp->title}» que agendaste.".($followUp->note ? " Nota: {$followUp->note}" : '').' Faz o que ficou combinado e, se for preciso, agenda o próximo.', TriggerType::Schedule, source: $followUp);
                    }
                });
        });

        $this->components->info("{$count} seguimento(s) avisado(s).");

        return self::SUCCESS;
    }

    private function link(FollowUp $followUp): string
    {
        return match ($followUp->subject_type) {
            EmailMessage::class => "/inbox/{$followUp->subject_id}",
            Tender::class => '/tenders',
            Contract::class => "/contracts/{$followUp->subject_id}",
            PurchaseRequest::class => "/procurement/{$followUp->subject_id}",
            default => '/',
        };
    }
}
