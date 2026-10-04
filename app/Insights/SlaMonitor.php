<?php

namespace App\Insights;

use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Support\TenantSettings;
use Illuminate\Support\Str;

/**
 * Client requests still waiting for a first answer (E08). The SLA is the
 * contract's when the sender's domain matches a client contract, otherwise
 * the tenant default.
 */
final class SlaMonitor
{
    /**
     * @return list<array{email_id: int, subject: string|null, from: string|null, hours_waiting: int, sla_hours: int, breached: bool}>
     */
    public function pending(): array
    {
        $default = TenantSettings::int('sla_response_hours');
        $contractSla = $this->contractSlaByDomain();
        $rows = [];

        $requests = EmailMessage::query()
            ->where('direction', 'inbound')
            ->where('classification', EmailCategory::ClientRequest->value)
            ->where('received_at', '>=', now()->subDays(30))
            ->orderBy('received_at')
            ->get();

        foreach ($requests as $email) {
            if ($this->answered($email)) {
                continue;
            }

            $sla = $contractSla[Str::lower(Str::after((string) $email->from_address, '@'))] ?? $default;
            $hours = (int) floor(($email->received_at ?? $email->created_at)->diffInMinutes(now()) / 60);

            $rows[] = ['email_id' => $email->id, 'subject' => $email->subject, 'from' => $email->from_address, 'hours_waiting' => $hours, 'sla_hours' => $sla, 'breached' => $hours >= $sla];
        }

        return $rows;
    }

    /**
     * @return list<array{email_id: int, subject: string|null, from: string|null, hours_waiting: int, sla_hours: int, breached: bool}>
     */
    public function breaches(): array
    {
        return array_values(array_filter($this->pending(), fn (array $row) => $row['breached']));
    }

    private function answered(EmailMessage $email): bool
    {
        if ($email->thread_id === null) {
            return false;
        }

        return EmailMessage::query()
            ->where('thread_id', $email->thread_id)
            ->where('direction', 'outbound')
            ->where('status', EmailStatus::Sent)
            ->where('created_at', '>=', $email->created_at)
            ->exists();
    }

    /**
     * @return array<string, int>
     */
    private function contractSlaByDomain(): array
    {
        return Contract::query()
            ->whereNotNull('sla_response_hours')
            ->whereNotNull('party_domain')
            ->whereIn('status', ['active', 'renewing'])
            ->get()
            ->mapWithKeys(fn (Contract $contract) => [Str::lower((string) $contract->party_domain) => (int) $contract->sla_response_hours])
            ->all();
    }
}
