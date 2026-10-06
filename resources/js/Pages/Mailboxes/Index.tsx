import { Head, router, useForm } from '@inertiajs/react';
import { AtSign, Bot, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { PasswordInput } from '@/Components/PasswordInput';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import SettingsLayout from '@/Layouts/SettingsLayout';

interface MailboxRow {
    id: number;
    address: string;
    display_name: string;
    status: 'provisioning' | 'active' | 'error' | 'disabled';
    status_label: string;
    imap_host: string | null;
    imap_port: number | null;
    imap_username: string | null;
    imap_encryption: string | null;
    imap_folder: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_username: string | null;
    smtp_encryption: string | null;
    has_imap_password: boolean;
    has_smtp_password: boolean;
    last_error: string | null;
    last_inbound_at: string | null;
    owners: { id: number; name: string }[];
    readers: { id: number; name: string; processes_new: boolean }[];
    mine: boolean;
}

interface Props {
    mailboxes: MailboxRow[];
    agents: { id: number; name: string }[];
    people: { id: number; name: string }[];
    default_reader_id: number | null;
    can: { manage_all: boolean };
}

const NONE = '__none__';

const tone = (status: MailboxRow['status']): Tone =>
    (({ active: 'success', error: 'danger', disabled: 'idle', provisioning: 'warning' }) as const)[status];

export default function Mailboxes({ mailboxes, agents, people, default_reader_id, can }: Props) {
    const [editing, setEditing] = useState<MailboxRow | 'new' | null>(null);
    const [removing, setRemoving] = useState<MailboxRow | null>(null);

    const newButton = (
        <Button onClick={() => setEditing('new')}>
            <Plus />
            Ligar caixa de email
        </Button>
    );

    return (
        <SettingsLayout>
            <Head title="Caixas de email" />

            <PageHeader
                title="Caixas de email"
                description="Cada pessoa tem as suas caixas. Escolha que agentes as lêem: resumem, criam tarefas e preparam rascunhos, mas nunca enviam em seu nome."
                actions={newButton}
            />

            {mailboxes.length === 0 ? (
                <EmptyState
                    icon={AtSign}
                    title="Ainda sem caixas de email"
                    description="Ligue a sua caixa (ou uma partilhada que gere, como financas@) com os dados IMAP e SMTP do seu fornecedor."
                    action={newButton}
                />
            ) : (
                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                    {mailboxes.map((mailbox) => (
                        <div key={mailbox.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-3">
                            <AtSign className="hidden size-4 shrink-0 text-muted-foreground sm:block" />
                            <div className="min-w-0 flex-1">
                                <div className="flex min-w-0 items-center gap-2">
                                    <span className="truncate text-sm font-medium">{mailbox.address}</span>
                                    <StatusBadge tone={tone(mailbox.status)}>{mailbox.status_label}</StatusBadge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {can.manage_all ? `De ${mailbox.owners.map((o) => o.name).join(', ') || 'ninguém'} · ` : ''}
                                    {mailbox.readers.length === 0
                                        ? 'Nenhum agente a lê'
                                        : `Lida por ${mailbox.readers.map((r) => (r.processes_new ? `${r.name} (vê o email novo)` : r.name)).join(', ')}`}
                                </p>
                                {mailbox.last_error && <p className="text-xs text-destructive">{mailbox.last_error}</p>}
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <Button variant="ghost" size="icon" className="size-7" onClick={() => setEditing(mailbox)} aria-label="Editar caixa">
                                    <Pencil />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    onClick={() => setRemoving(mailbox)}
                                    aria-label="Remover caixa"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {editing !== null && (
                <MailboxDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    mailbox={editing === 'new' ? null : editing}
                    agents={agents}
                    people={people}
                    defaultReaderId={default_reader_id}
                    manageAll={can.manage_all}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remover ${removing?.address ?? ''}?`}
                description="Os emails já recebidos desta caixa são apagados da plataforma. A caixa no seu fornecedor fica como está."
                confirmLabel="Remover"
                destructive
                onConfirm={() =>
                    removing && router.delete(`/settings/mailboxes/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) })
                }
            />
        </SettingsLayout>
    );
}

function MailboxDialog({
    mailbox,
    agents,
    people,
    defaultReaderId,
    manageAll,
    onClose,
}: {
    mailbox: MailboxRow | null;
    agents: Props['agents'];
    people: Props['people'];
    defaultReaderId: number | null;
    manageAll: boolean;
    onClose: () => void;
}) {
    const initialReaders = mailbox ? mailbox.readers.map((r) => r.id) : defaultReaderId ? [defaultReaderId] : [];
    const form = useForm({
        address: mailbox?.address ?? '',
        display_name: mailbox?.display_name ?? '',
        status: mailbox?.status === 'disabled' ? 'disabled' : 'active',
        imap_host: mailbox?.imap_host ?? '',
        imap_port: mailbox?.imap_port ? String(mailbox.imap_port) : '993',
        imap_username: mailbox?.imap_username ?? '',
        imap_password: '',
        imap_encryption: mailbox?.imap_encryption ?? 'ssl',
        smtp_host: mailbox?.smtp_host ?? '',
        smtp_port: mailbox?.smtp_port ? String(mailbox.smtp_port) : '465',
        smtp_username: mailbox?.smtp_username ?? '',
        smtp_password: '',
        smtp_encryption: mailbox?.smtp_encryption ?? 'ssl',
        owners: mailbox?.owners.map((o) => o.id) ?? [],
        readers: initialReaders,
        processor: mailbox ? (mailbox.readers.find((r) => r.processes_new)?.id ?? null) : (defaultReaderId ?? null),
    });

    const toggleReader = (id: number, on: boolean) => {
        const readers = on ? [...form.data.readers, id] : form.data.readers.filter((r) => r !== id);
        form.setData((data) => ({ ...data, readers, processor: readers.includes(data.processor ?? -1) ? data.processor : null }));
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            imap_port: data.imap_port ? Number(data.imap_port) : null,
            smtp_port: data.smtp_port ? Number(data.smtp_port) : null,
            owners: manageAll && data.owners.length > 0 ? data.owners : undefined,
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (mailbox) {
            form.put(`/settings/mailboxes/${mailbox.id}`, options);
        } else {
            form.post('/settings/mailboxes', options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={mailbox ? `Editar ${mailbox.address}` : 'Ligar caixa de email'}
            description="Os dados de acesso ficam cifrados. Os agentes que escolher lêem esta caixa; só os donos enviam a partir dela."
            submitLabel={mailbox ? 'Guardar' : 'Ligar caixa'}
            processing={form.processing}
            disabled={form.data.address.trim() === '' || form.data.display_name.trim() === ''}
            onSubmit={submit}
            size="lg"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field id="address" label="Endereço" error={errors.address}>
                    <Input
                        id="address"
                        type="email"
                        value={form.data.address}
                        onChange={(e) => form.setData('address', e.target.value)}
                        placeholder="ana@empresa.co.mz"
                    />
                </Field>
                <Field id="display_name" label="Nome que aparece" error={errors.display_name}>
                    <Input
                        id="display_name"
                        value={form.data.display_name}
                        onChange={(e) => form.setData('display_name', e.target.value)}
                        placeholder="Ana Sitoe"
                    />
                </Field>
            </div>

            {manageAll && (
                <Field id="owners" label="Donos" error={errors.owners} hint="Quem lê e envia a partir desta caixa. Sem ninguém marcado, fica sua.">
                    <div className="grid max-h-40 gap-1.5 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                        {people.map((person) => (
                            <label key={person.id} className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.owners.includes(person.id)}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'owners',
                                            checked === true ? [...form.data.owners, person.id] : form.data.owners.filter((o) => o !== person.id),
                                        )
                                    }
                                />
                                {person.name}
                            </label>
                        ))}
                    </div>
                </Field>
            )}

            <Field
                id="readers"
                label="Agentes que lêem esta caixa"
                error={errors.readers}
                hint="Resumem, criam tarefas para si e preparam rascunhos de resposta. Nunca enviam."
            >
                <div className="grid max-h-40 gap-1.5 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                    {agents.map((agent) => (
                        <label key={agent.id} className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.readers.includes(agent.id)}
                                onCheckedChange={(checked) => toggleReader(agent.id, checked === true)}
                            />
                            <Bot className="size-3.5 text-muted-foreground" />
                            {agent.name}
                        </label>
                    ))}
                </div>
            </Field>

            <Field
                id="processor"
                label="Quem vê cada email novo"
                error={errors.processor}
                hint="Este agente lê cada email que chega e diz-lhe o que precisa de si."
            >
                <Select
                    value={form.data.processor ? String(form.data.processor) : NONE}
                    onValueChange={(value) => form.setData('processor', value === NONE ? null : Number(value))}
                >
                    <SelectTrigger id="processor" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Ninguém, só quando eu pedir</SelectItem>
                        {agents
                            .filter((agent) => form.data.readers.includes(agent.id))
                            .map((agent) => (
                                <SelectItem key={agent.id} value={String(agent.id)}>
                                    {agent.name}
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
            </Field>

            <ServerFields prefix="imap" title="Receber (IMAP)" form={form} hasPassword={mailbox?.has_imap_password ?? false} errors={errors} />
            <ServerFields prefix="smtp" title="Enviar (SMTP)" form={form} hasPassword={mailbox?.has_smtp_password ?? false} errors={errors} />

            {mailbox && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.status === 'active'}
                        onCheckedChange={(checked) => form.setData('status', checked === true ? 'active' : 'disabled')}
                    />
                    Caixa activa (lê o email novo de minuto a minuto)
                </label>
            )}
        </FormDialog>
    );
}

type ServerKey = 'host' | 'port' | 'username' | 'password' | 'encryption';

function ServerFields({
    prefix,
    title,
    form,
    hasPassword,
    errors,
}: {
    prefix: 'imap' | 'smtp';
    title: string;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    form: { data: Record<string, any>; setData: (key: any, value: any) => void };
    hasPassword: boolean;
    errors: Record<string, string | undefined>;
}) {
    const key = (k: ServerKey) => `${prefix}_${k}`;

    return (
        <fieldset className="flex flex-col gap-3 rounded-lg border p-3">
            <legend className="px-1 text-xs font-medium text-muted-foreground">{title}</legend>
            <div className="grid gap-3 sm:grid-cols-[1fr_6rem_7rem]">
                <Field id={key('host')} label="Servidor" error={errors[key('host')]}>
                    <Input
                        id={key('host')}
                        value={form.data[key('host')]}
                        onChange={(e) => form.setData(key('host'), e.target.value)}
                        placeholder={`${prefix}.hostinger.com`}
                    />
                </Field>
                <Field id={key('port')} label="Porta" error={errors[key('port')]}>
                    <Input
                        id={key('port')}
                        inputMode="numeric"
                        value={form.data[key('port')]}
                        onChange={(e) => form.setData(key('port'), e.target.value)}
                    />
                </Field>
                <Field id={key('encryption')} label="Segurança" error={errors[key('encryption')]}>
                    <Select value={form.data[key('encryption')] || 'none'} onValueChange={(value) => form.setData(key('encryption'), value)}>
                        <SelectTrigger id={key('encryption')} className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ssl">SSL</SelectItem>
                            <SelectItem value="tls">TLS</SelectItem>
                            <SelectItem value="none">Nenhuma</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field id={key('username')} label="Utilizador" error={errors[key('username')]}>
                    <Input id={key('username')} value={form.data[key('username')]} onChange={(e) => form.setData(key('username'), e.target.value)} />
                </Field>
                <Field
                    id={key('password')}
                    label="Palavra-passe"
                    error={errors[key('password')]}
                    hint={hasPassword ? 'Em branco mantém a guardada.' : undefined}
                >
                    <PasswordInput
                        id={key('password')}
                        value={form.data[key('password')]}
                        onChange={(e) => form.setData(key('password'), e.target.value)}
                        autoComplete="new-password"
                    />
                </Field>
            </div>
        </fieldset>
    );
}
