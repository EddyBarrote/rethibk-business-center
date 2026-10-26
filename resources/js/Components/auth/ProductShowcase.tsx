import { BookOpen, Check, Database, Mail, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import { Monogram } from '@/Components/Blocks';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { buttonVariants } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The sign-in panel's picture, drawn with the console's own pieces instead of
 * an image: a conversation with an agent, the task it works on, the approval
 * it waits for and what it is connected to. Theme tokens only, so it follows
 * the theme and light/dark by itself. Decorative: hidden from screen readers
 * and from the keyboard.
 */
export function ProductShowcase({ className }: { className?: string }) {
    return (
        <div className={cn('relative mx-auto w-full max-w-md select-none', className)} aria-hidden="true" inert>
            <div className="absolute top-1/3 left-1/2 size-72 -translate-x-1/2 rounded-full bg-primary/15 blur-3xl" />

            <div className="relative space-y-4">
                <Conversation />
                <Workflow className="ml-auto w-[88%] -rotate-1" />
                <Approval className="w-[82%] rotate-1" />
                <Connections />
            </div>
        </div>
    );
}

function Panel({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('rounded-xl border bg-card/95 p-4 text-card-foreground shadow-lg backdrop-blur-sm', className)}>{children}</div>;
}

function Conversation() {
    return (
        <Panel className="w-[92%]">
            <div className="flex items-center gap-2.5">
                <Monogram name="Chief of Staff" agent />
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Chief of Staff</p>
                    <p className="text-xs text-muted-foreground">Agente de IA</p>
                </div>
                <StatusBadge tone="running">A trabalhar</StatusBadge>
            </div>

            <p className="mt-3 ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-primary px-3 py-2 text-sm text-primary-foreground">
                Prepara a proposta para o pedido de cotação que chegou hoje.
            </p>

            <div className="mt-3 flex items-center gap-1.5 text-sm">
                <svg viewBox="0 0 24 24" className="thinking-spark size-3.5 shrink-0 text-primary">
                    <path
                        fill="currentColor"
                        d="M12 2c.4 3.9 1.3 6.2 2.8 7.2C16.3 10.3 18.6 11 22 12c-3.4 1-5.7 1.7-7.2 2.8-1.5 1-2.4 3.3-2.8 7.2-.4-3.9-1.3-6.2-2.8-7.2C7.7 13.7 5.4 13 2 12c3.4-1 5.7-1.7 7.2-2.8C10.7 8.2 11.6 5.9 12 2Z"
                    />
                </svg>
                <span className="thinking-text">A consultar os preços no ERP…</span>
            </div>
        </Panel>
    );
}

const steps: { label: string; tone: Tone }[] = [
    { label: 'Ler o email do cliente', tone: 'success' },
    { label: 'Consultar preços no ERP', tone: 'success' },
    { label: 'Preparar a proposta', tone: 'running' },
    { label: 'Aprovação da direcção', tone: 'idle' },
];

function Workflow({ className }: { className?: string }) {
    return (
        <Panel className={className}>
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">Proposta de bilhética</p>
                <span className="tabular text-xs text-muted-foreground">2 de 4</span>
            </div>

            <ol className="mt-3 space-y-2">
                {steps.map((step) => (
                    <li key={step.label} className="flex items-center gap-2.5 text-sm">
                        {step.tone === 'success' ? (
                            <span className="inline-flex size-4 items-center justify-center rounded-full bg-status-success text-white">
                                <Check className="size-3" strokeWidth={3} />
                            </span>
                        ) : (
                            <span className="inline-flex size-4 items-center justify-center">
                                <StatusDot tone={step.tone} />
                            </span>
                        )}
                        <span
                            className={cn(
                                step.tone === 'idle' ? 'text-muted-foreground' : 'text-foreground',
                                step.tone === 'running' && 'font-medium',
                            )}
                        >
                            {step.label}
                        </span>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

function Approval({ className }: { className?: string }) {
    return (
        <Panel className={className}>
            <div className="flex items-start gap-2.5">
                <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-status-warning/15 text-warning-strong">
                    <ShieldCheck className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Enviar a proposta ao cliente</p>
                    <p className="text-xs text-muted-foreground">Gestor de Clientes pede a sua aprovação</p>
                </div>
            </div>

            <div className="mt-3 flex justify-end gap-2">
                <span className={buttonVariants({ variant: 'outline', size: 'sm' })}>Rejeitar</span>
                <span className={buttonVariants({ size: 'sm' })}>
                    <Check />
                    Aprovar
                </span>
            </div>
        </Panel>
    );
}

const connections = [
    { label: 'ERP', icon: Database },
    { label: 'Email', icon: Mail },
    { label: 'Conhecimento', icon: BookOpen },
];

function Connections() {
    return (
        <div className="flex items-center justify-center gap-2 pt-1">
            {connections.map(({ label, icon: Icon }) => (
                <span
                    key={label}
                    className="inline-flex items-center gap-1.5 rounded-full border bg-card/90 px-3 py-1 text-xs text-muted-foreground shadow-sm"
                >
                    <Icon className="size-3.5 text-primary" />
                    {label}
                </span>
            ))}
        </div>
    );
}
