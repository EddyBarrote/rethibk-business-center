import type { Tone } from '@/Components/Status';

export interface KnowledgeRow {
    id: number;
    type: string;
    type_label: string;
    title: string;
    excerpt: string;
    score: number | null;
    status: 'published' | 'pending_review' | 'rejected';
    is_external: boolean;
    embedding_status: string;
    domain: { name: string; slug: string; color: string | null } | null;
    folder: { id: number; name: string } | null;
    file: {
        name: string;
        size: number | null;
        extension: string;
        pages: number | null;
        version: number;
        reading: 'pending' | 'reading' | 'done' | 'failed' | null;
        text_source: 'text' | 'model' | 'mixed' | 'none' | null;
        thumbnail: string | null;
    } | null;
    /** In search results: the page the excerpt is on. */
    page: number | null;
    author: { kind: 'agent' | 'user' | 'system'; name: string; id: number | null };
    created_at: string;
    updated_at: string;
}

export interface DomainSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    color: string | null;
    restricted: boolean;
    items?: number;
}

export interface FolderOption {
    id: number;
    domain_id: number;
    path: string;
}

/** Review state, shown only when it is not "published". */
export const reviewState = (status: KnowledgeRow['status']): { tone: Tone; label: string } | null =>
    ({
        pending_review: { tone: 'warning' as Tone, label: 'Para rever' },
        rejected: { tone: 'danger' as Tone, label: 'Rejeitado' },
    })[status as string] ?? null;

/** Indexing state, shown only when it is not the normal "done". */
export const indexState = (status: string): { tone: Tone; label: string } | null =>
    ({
        pending: { tone: 'running' as Tone, label: 'A indexar' },
        failed: { tone: 'danger' as Tone, label: 'Indexação falhou' },
    })[status] ?? null;
