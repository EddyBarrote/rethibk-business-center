<?php

namespace App\Console\Commands\Watch;

use App\Ai\Runs\AgentDirectory;
use App\Enums\Role;
use App\Enums\TenderStatus;
use App\Models\EmailMessage;
use App\Models\Tender;
use App\Models\User;
use App\Support\Notifier;
use App\Support\TenantSettings;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Deadline watch (E03): emails and tenders whose deadline is closer than
 * the tenant's warning window are flagged once to whoever owns them.
 */
#[Signature('agents:watch-deadlines')]
#[Description('Avisa dos prazos de emails e concursos que estão a chegar (de hora a hora)')]
class WatchDeadlines extends Command
{
    public function handle(TenantManager $tenants, Notifier $notifier, AgentDirectory $agents): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($notifier, $agents, &$count): void {
            $until = now()->addHours(TenantSettings::int('deadline_warning_hours'));
            $fallback = $agents->forRole('triage')->reportsTo ?? User::query()->where('role', Role::Owner)->where('is_active', true)->first();

            foreach (EmailMessage::query()->where('direction', 'inbound')->whereBetween('deadline_at', [now(), $until])->with('routedTo')->get() as $email) {
                if ($this->once("email:{$email->id}")) {
                    $person = $email->routedTo ?? $fallback;
                    $person && $notifier->notify($person, 'Prazo a chegar: '.$email->subject, 'Termina '.$email->deadline_at?->diffForHumans().'. '.$email->summary, "/inbox/{$email->id}", 'Vigilância de prazos', 'warning');
                    $count++;
                }
            }

            foreach (Tender::query()->whereIn('status', [TenderStatus::New, TenderStatus::Reviewing, TenderStatus::Bidding])->whereBetween('deadline_at', [now(), $until])->get() as $tender) {
                if ($this->once("tender:{$tender->id}")) {
                    $fallback && $notifier->notify($fallback, 'Concurso a fechar: '.$tender->title, 'Submissão termina '.$tender->deadline_at?->diffForHumans().' ('.$tender->status->label().').', '/tenders', 'Vigilância de prazos', 'warning');
                    $count++;
                }
            }
        });

        $this->components->info("{$count} aviso(s) de prazo.");

        return self::SUCCESS;
    }

    private function once(string $key): bool
    {
        return Cache::add('deadline-warned:'.app(TenantManager::class)->id().':'.$key, true, now()->addDays(14));
    }
}
