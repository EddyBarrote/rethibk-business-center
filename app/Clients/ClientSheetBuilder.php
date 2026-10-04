<?php

namespace App\Clients;

use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The live client sheet (E08): ERP data joined with what the platform
 * knows (emails, contracts, follow-ups). Used by the Client Manager agent
 * and by the /clients screen.
 */
final class ClientSheetBuilder
{
    public function __construct(private readonly ErpGateway $erp) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ErpException when the ERP cannot be reached or the client does not exist
     */
    public function build(string $accountId, ?Model $actor = null): array
    {
        $account = $this->erp->call('crm.get_account', ['account_id' => $accountId], $actor);

        if (! $account->ok) {
            throw new ErpException((string) $account->error());
        }

        $data = (array) $account->data;
        $info = (array) ($data['account'] ?? $data);
        $contacts = (array) ($data['contacts'] ?? []);
        $domains = $this->domains($info, $contacts);

        $emails = EmailMessage::query()
            ->where(function ($query) use ($domains): void {
                foreach ($domains as $domain) {
                    $query->orWhere('from_address', 'like', '%@'.$domain);
                }
            })
            ->when($domains === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('received_at')
            ->limit(15)
            ->get(['id', 'subject', 'from_address', 'classification', 'summary', 'received_at', 'thread_id']);

        return [
            'account' => $info,
            'contacts' => $contacts,
            'balance_due' => $data['balance_due'] ?? null,
            'projects' => $this->optional('projects.list_by_account', ['account_id' => $accountId], 'projects', $actor),
            'receivables' => $this->optional('invoices.list_receivables', ['account_id' => $accountId], 'receivables', $actor),
            'leads' => $this->optional('leads.search', ['account_id' => $accountId], 'leads', $actor),
            'contracts' => Contract::query()->where('party_ref', $accountId)->get(['id', 'title', 'value', 'ends_at', 'status', 'sla_response_hours'])->toArray(),
            'recent_emails' => $emails->map(fn (EmailMessage $e) => [
                'id' => $e->id, 'subject' => $e->subject, 'from' => $e->from_address, 'category' => $e->classification,
                'summary' => $e->summary, 'received_at' => $e->received_at?->toIso8601String(), 'link' => "/inbox/{$e->id}",
            ])->all(),
            'follow_ups' => FollowUp::query()->whereNull('done_at')->where('title', 'like', '%'.Str::limit((string) ($info['name'] ?? ''), 20, '').'%')->get(['id', 'title', 'due_at'])->toArray(),
            'tenders' => Tender::query()->where('entity', 'like', '%'.Str::limit((string) ($info['name'] ?? ''), 20, '').'%')->get(['id', 'title', 'status', 'deadline_at'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<mixed>|null
     */
    private function optional(string $tool, array $arguments, string $key, ?Model $actor): ?array
    {
        try {
            $result = $this->erp->call($tool, $arguments, $actor);
        } catch (ErpException) {
            return null;
        }

        return $result->ok ? array_values((array) ($result->data[$key] ?? [])) : null;
    }

    /**
     * Email domains of the client, from its own address and its contacts.
     *
     * @param  array<string, mixed>  $account
     * @param  array<int, mixed>  $contacts
     * @return list<string>
     */
    private function domains(array $account, array $contacts): array
    {
        $addresses = [$account['email'] ?? null, ...array_map(fn ($c) => is_array($c) ? ($c['email'] ?? null) : null, $contacts)];
        $generic = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com'];

        return array_values(array_unique(array_filter(
            array_map(fn ($a) => is_string($a) && str_contains($a, '@') ? Str::lower(Str::after($a, '@')) : null, $addresses),
            fn ($d) => $d !== null && ! in_array($d, $generic, true),
        )));
    }
}
