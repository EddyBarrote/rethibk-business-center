import { Link, router } from '@inertiajs/react';
import { UserPlus, X } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

export interface Collaborator {
    id: number;
    kind: 'agent' | 'user';
    name: string;
    avatar_url: string | null;
    role?: string;
    role_label: string;
    subtask_id?: number | null;
    status: string | null;
    status_label?: string | null;
}

export interface CollaboratorRoleOption {
    value: string;
    label: string;
    hint: string;
}

/** How a collaborator's part is going, in words: from the status of their sub-task. */
export function partLabel(collaborator: Pick<Collaborator, 'kind' | 'status'>): string {
    switch (collaborator.status) {
        case 'in_progress':
            return collaborator.kind === 'agent' ? 'A trabalhar' : 'Em curso';
        case 'in_review':
        case 'waiting_human':
            return 'Pronto para rever';
        case 'blocked':
            return 'Bloqueado';
        case 'done':
            return 'Feito';
        case 'cancelled':
            return 'Cancelado';
        default:
            return 'Convidado';
    }
}

/** A face: an agent's photo or initials, a person's monogram. */
export function CollaboratorFace({
    collaborator,
    className,
}: {
    collaborator: Pick<Collaborator, 'kind' | 'name' | 'avatar_url'>;
    className?: string;
}) {
    return collaborator.kind === 'agent' ? (
        <AgentAvatar name={collaborator.name} url={collaborator.avatar_url} className={cn('size-6 rounded-md text-[9px]', className)} />
    ) : (
        <Monogram name={collaborator.name} className={cn('size-6 rounded-full text-[9px]', className)} />
    );
}

/**
 * «Com quem?» on a task (docs/DECISOES.md, "Tarefas: colaborar com agentes e
 * colegas"): the faces of who works on it, and a dialog to invite an agent or
 * a colleague with a role, see how each part is going and take someone out.
 */
export function Collaborators({
    taskId,
    collaborators,
    agents,
    people,
    roles,
    canEdit,
}: {
    taskId: number;
    collaborators: Collaborator[];
    agents: { id: number; name: string }[];
    people: { id: number; name: string }[];
    roles: CollaboratorRoleOption[];
    canEdit: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [who, setWho] = useState('');
    const [role, setRole] = useState(roles[0]?.value ?? 'preparar');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const invite = () => {
        const [kind, id] = who.split(':');
        router.post(
            `/tasks/${taskId}/collaborators`,
            { [kind === 'agent' ? 'agent_id' : 'user_id']: Number(id), role, note: note || null },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onSuccess: () => {
                    setWho('');
                    setNote('');
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex h-8 items-center gap-1.5 rounded-md px-1.5 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                title={collaborators.length > 0 ? collaborators.map((c) => `${c.name} · ${c.role_label}`).join('\n') : 'Convidar agentes ou colegas'}
            >
                {collaborators.length > 0 && (
                    <span className="flex -space-x-1.5">
                        {collaborators.slice(0, 4).map((c) => (
                            <CollaboratorFace key={c.id} collaborator={c} className="ring-2 ring-background" />
                        ))}
                    </span>
                )}
                {collaborators.length > 4 && <span className="tabular-nums">+{collaborators.length - 4}</span>}
                {collaborators.length === 0 && <UserPlus className="size-4" />}
                <span className="hidden sm:inline">Com quem?</span>
            </button>

            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title="Com quem?"
                description="Convide um agente ou um colega para uma parte desta tarefa. Cada um recebe uma subtarefa, e o que fizer aparece aqui na conversa."
                submitLabel="Convidar"
                processing={busy}
                disabled={!canEdit || who === ''}
                onSubmit={invite}
            >
                {collaborators.length > 0 && (
                    <ul className="flex flex-col gap-1">
                        {collaborators.map((c) => (
                            <li key={c.id} className="flex items-center gap-2.5 py-1 text-sm">
                                <CollaboratorFace collaborator={c} className="size-7" />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{c.name}</span>
                                    <span className="block text-xs text-muted-foreground">
                                        {c.role_label} ·{' '}
                                        {c.subtask_id ? (
                                            <Link href={`/tasks/${c.subtask_id}`} className="hover:underline">
                                                {partLabel(c)}
                                            </Link>
                                        ) : (
                                            partLabel(c)
                                        )}
                                    </span>
                                </span>
                                {canEdit && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-7 text-muted-foreground"
                                        aria-label={`Retirar ${c.name}`}
                                        title="Retirar da tarefa"
                                        onClick={() => router.delete(`/tasks/${taskId}/collaborators/${c.id}`, { preserveScroll: true })}
                                    >
                                        <X />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canEdit && (
                    <>
                        <Field id="collab-who" label="Quem">
                            <NativeSelect id="collab-who" value={who} onChange={(event) => setWho(event.target.value)}>
                                <option value="">Escolher…</option>
                                <optgroup label="Agentes">
                                    {agents.map((agent) => (
                                        <option key={agent.id} value={`agent:${agent.id}`}>
                                            {agent.name}
                                        </option>
                                    ))}
                                </optgroup>
                                <optgroup label="Colegas">
                                    {people.map((person) => (
                                        <option key={person.id} value={`user:${person.id}`}>
                                            {person.name}
                                        </option>
                                    ))}
                                </optgroup>
                            </NativeSelect>
                        </Field>
                        <fieldset className="flex flex-col gap-1.5">
                            <legend className="mb-1.5 text-sm font-medium">Para quê</legend>
                            {roles.map((option) => (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2.5 rounded-md px-2.5 py-1.5 text-sm hover:bg-accent/50',
                                        role === option.value && 'bg-accent',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="collab-role"
                                        value={option.value}
                                        checked={role === option.value}
                                        onChange={() => setRole(option.value)}
                                        className="mt-1 accent-primary"
                                    />
                                    <span>
                                        <span className="font-medium">{option.label}</span>
                                        <span className="block text-xs text-muted-foreground">{option.hint}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        <Field id="collab-note" label="Nota" hint="Opcional: o que precisa em concreto.">
                            <Textarea id="collab-note" rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
                        </Field>
                    </>
                )}
            </FormDialog>
        </>
    );
}
