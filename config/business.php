<?php

/*
 * Defaults for the business agents (E03 to E08). Each can be overridden per
 * tenant in tenants.settings['business'] from the super admin console;
 * see docs/DECISOES.md for why each default was chosen.
 */
return [
    // Hours a client request may wait for a first answer (E08).
    'sla_response_hours' => (int) env('BUSINESS_SLA_HOURS', 24),

    // Email and tender deadlines closer than this are flagged (E03).
    'deadline_warning_hours' => 48,

    // Project margin alerts (E05).
    'min_margin_pct' => 15,
    'budget_alert_pct' => 90,

    // Contracts are flagged this many days before ending, unless the
    // contract has its own notice period (E06, E08).
    'contract_notice_days' => 60,

    // Bank lines left unreconciled longer than this are an issue (E05).
    'unreconciled_days' => 7,

    // Agents wake on their own for work of theirs that has been quiet this
    // long, Paperclip's heartbeat (realinhamento L6). 0 turns it off.
    'heartbeat_minutes' => 60,
];
