export type Role = 'owner' | 'admin' | 'manager' | 'member';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label: string;
    can_manage_tenant: boolean;
    is_manager: boolean;
}

export interface SharedProps {
    [key: string]: unknown;
    app: { name: string; locale: string };
    tenant: { id: number; name: string; slug: string } | null;
    admin: { id: number; name: string; email: string } | null;
    auth: { user: AuthUser | null; pending_approvals: number; unread_notifications: number; waiting_tasks: number };
    flash: { success: string | null; error: string | null };
    sidebar_agents: { id: number; name: string; status: 'active' | 'suspended'; running: number }[];
}

export interface Option {
    value: string;
    label: string;
}

export interface LevelOption {
    value: number;
    code: string;
    label: string;
}

export interface AgentSummary {
    id: number;
    key: string;
    name: string;
    title: string | null;
    description: string | null;
    status: 'draft' | 'active' | 'suspended';
    status_label: string;
    suspended_reason: string | null;
    autonomy_level: number;
    department: string | null;
    reports_to: string | null;
}

export type RunStatus = 'queued' | 'running' | 'awaiting_approval' | 'completed' | 'failed' | 'cancelled';

export interface RunSummary {
    id: number;
    agent: { id: number; name: string };
    trigger: string;
    trigger_label: string;
    status: RunStatus;
    status_label: string;
    input: string;
    output: string | null;
    requested_by: string | null;
    provider: string | null;
    model: string | null;
    input_tokens: number;
    output_tokens: number;
    cost_usd: number;
    duration_ms: number | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
}

export interface ApprovalSummary {
    id: number;
    run_id: number;
    agent: { id: number; name: string };
    action_type: string;
    action_summary: string;
    payload: Record<string, unknown> | null;
    required_level: number;
    agent_level: number;
    ceiling_reason: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    status_label: string;
    assigned_to: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    execution_status: 'not_executed' | 'executed' | 'failed';
    execution_result: { ok?: boolean; content?: string; data?: unknown } | null;
    created_at: string;
    can_decide: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface BriefingSummary {
    id: number;
    type: string;
    type_label: string;
    title: string;
    highlights: string[];
    decisions_pending: { title: string; link?: string | null; owner?: string | null }[];
    agent: string | null;
    for: string | null;
    run_id: number | null;
    read: boolean;
    created_at: string;
    content?: string;
}

export interface Issue {
    area: string;
    severity: string;
    issue: string;
    link: string | null;
}
