<?php

namespace App\Ai\Runs;

use App\Enums\StepType;
use App\Models\AgentRunStep;
use App\Models\Approval;

/**
 * Where the recipients of an email waiting for approval came from: the ERP
 * record the agent read in the same run that carries each address. The
 * approver sees the contact as the ERP has it (name, role, customer) next to
 * what the agent wrote, or a warning when no ERP read in the run carries it.
 */
final class RecipientSources
{
    /**
     * @return list<array{email: string, found: bool, name: string|null, title: string|null, customer: string|null, primary: bool, tool: string|null}>|null
     *                                                                                                                                                      null when the action has no recipients
     */
    public function forApproval(Approval $approval): ?array
    {
        $recipients = $this->recipients($approval->payload ?? []);

        if ($recipients === []) {
            return null;
        }

        $records = $this->erpRecords($approval->agent_run_id);

        return array_map(function (string $email) use ($records): array {
            $record = $records[mb_strtolower($email)] ?? null;

            return [
                'email' => $email,
                'found' => $record !== null,
                'name' => $record['name'] ?? null,
                'title' => $record['title'] ?? null,
                'customer' => $record['customer'] ?? null,
                'primary' => $record['primary'] ?? false,
                'tool' => $record['tool'] ?? null,
            ];
        }, $recipients);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recipients(array $payload): array
    {
        $emails = [];

        foreach (['to', 'cc', 'bcc'] as $field) {
            foreach ((array) ($payload[$field] ?? []) as $email) {
                if (is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                    $emails[] = trim($email);
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Every record with an email address in the run's ERP results, keyed by
     * the lower-cased address. The first record found for an address wins.
     *
     * @return array<string, array{name: string|null, title: string|null, customer: string|null, primary: bool, tool: string}>
     */
    private function erpRecords(?int $runId): array
    {
        if ($runId === null) {
            return [];
        }

        $records = [];

        AgentRunStep::query()
            ->where('agent_run_id', $runId)
            ->where('type', StepType::ToolResult)
            ->where('tool_name', 'like', 'erp.%')
            ->orderBy('seq')
            ->each(function (AgentRunStep $step) use (&$records): void {
                $content = $step->payload['content'] ?? null;
                $data = is_string($content) ? json_decode($content, true) : $content;

                if (is_array($data)) {
                    $this->collect($data, null, (string) $step->tool_name, $records);
                }
            });

        return $records;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, array{name: string|null, title: string|null, customer: string|null, primary: bool, tool: string}>  $records
     */
    private function collect(array $node, ?string $customer, string $tool, array &$records): void
    {
        // A customer record carries its contacts underneath it.
        $customer = $this->text($node['customer'] ?? null) ?? $this->text($node['company'] ?? null) ?? $customer;
        $email = $this->text($node['email'] ?? null);

        if ($email !== null && ! isset($records[mb_strtolower($email)])) {
            $name = $this->text(trim(($this->text($node['firstname'] ?? null) ?? '').' '.($this->text($node['lastname'] ?? null) ?? '')))
                ?? $this->text($node['name'] ?? null);

            $records[mb_strtolower($email)] = [
                'name' => $name,
                'title' => $this->text($node['title'] ?? null),
                'customer' => $customer,
                'primary' => (string) ($node['is_primary'] ?? '') === '1',
                'tool' => $tool,
            ];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collect($child, $customer, $tool, $records);
            }
        }
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
