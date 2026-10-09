import { router } from '@inertiajs/react';
import { Check, CircleHelp, CornerDownLeft, PenLine } from 'lucide-react';
import { type KeyboardEvent, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { time } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * An agent's questions (docs/DECISOES.md, "Perguntas dos agentes"), like
 * Claude's AskUserQuestion: one to four questions, each with two to four
 * options and «Outra» for free text. Answering resumes the agent's run.
 */

export interface AgentQuestionItem {
    question: string;
    header: string;
    options: { label: string; description: string }[];
    multi_select: boolean;
}

export interface AgentQuestionAnswer {
    question: string;
    selected: string[];
    other: string | null;
}

export interface AgentQuestions {
    id: number;
    status: 'pending' | 'answered' | 'cancelled';
    questions: AgentQuestionItem[];
    answers: AgentQuestionAnswer[] | null;
    answered_by: string | null;
    answered_at: string | null;
    can_answer: boolean;
}

interface Draft {
    selected: string[];
    other: string | null;
}

const answered = (draft: Draft) => draft.selected.length > 0 || (draft.other !== null && draft.other.trim() !== '');

export function QuestionCard({ question }: { question: AgentQuestions }) {
    if (question.status === 'answered' && question.answers) {
        return <AnsweredCard question={question} answers={question.answers} />;
    }

    if (question.status === 'cancelled') {
        return (
            <div className="w-full max-w-xl rounded-xl border border-dashed px-4 py-3 text-xs text-muted-foreground">
                <p className="mb-1 font-medium">Perguntas que ficaram sem resposta</p>
                <ul className="list-disc space-y-0.5 pl-4">
                    {question.questions.map((item) => (
                        <li key={item.question}>{item.question}</li>
                    ))}
                </ul>
            </div>
        );
    }

    return <PendingCard question={question} />;
}

function PendingCard({ question }: { question: AgentQuestions }) {
    const items = question.questions;
    const [current, setCurrent] = useState(0);
    const [drafts, setDrafts] = useState<Draft[]>(() => items.map(() => ({ selected: [], other: null })));
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const item = items[current];
    const draft = drafts[current];
    const last = current === items.length - 1;
    const complete = drafts.every(answered);
    const disabled = !question.can_answer || sending;

    const update = (index: number, next: Draft) => setDrafts((all) => all.map((value, i) => (i === index ? next : value)));

    const choose = (label: string) => {
        if (disabled) {
            return;
        }
        if (item.multi_select) {
            const selected = draft.selected.includes(label) ? draft.selected.filter((value) => value !== label) : [...draft.selected, label];
            update(current, { ...draft, selected });

            return;
        }
        update(current, { selected: [label], other: null });
        // A single choice moves on by itself, like Claude's.
        if (!last) {
            setTimeout(() => setCurrent((index) => Math.min(index + 1, items.length - 1)), 160);
        }
    };

    const chooseOther = () => {
        if (disabled) {
            return;
        }
        update(current, item.multi_select ? { ...draft, other: draft.other ?? '' } : { selected: [], other: draft.other ?? '' });
    };

    const submit = () => {
        if (!complete || disabled) {
            return;
        }
        setSending(true);
        setError(null);
        router.post(
            `/questions/${question.id}/answer`,
            { answers: drafts.map((value) => ({ selected: value.selected, other: value.other?.trim() || null })) },
            {
                preserveScroll: true,
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Não foi possível enviar as respostas.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.target instanceof HTMLTextAreaElement) {
            return;
        }
        const number = Number(event.key);
        if (number >= 1 && number <= item.options.length) {
            event.preventDefault();
            choose(item.options[number - 1].label);
        } else if (event.key === 'Enter' && complete) {
            event.preventDefault();
            submit();
        }
    };

    return (
        <div
            tabIndex={-1}
            onKeyDown={onKeyDown}
            className="w-full max-w-xl overflow-hidden rounded-xl border border-primary/30 bg-card shadow-sm outline-none focus-within:ring-[3px] focus-within:ring-ring/20"
        >
            <div className="flex items-center gap-2 border-b bg-muted/40 px-3 py-2">
                <CircleHelp className="size-4 shrink-0 text-primary" />
                {items.length > 1 ? (
                    <div role="tablist" className="-my-1 flex min-w-0 flex-1 gap-1 overflow-x-auto">
                        {items.map((value, index) => (
                            <button
                                key={value.question}
                                type="button"
                                role="tab"
                                aria-selected={index === current}
                                onClick={() => setCurrent(index)}
                                className={cn(
                                    'inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-2 text-xs font-medium transition-colors',
                                    index === current ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {answered(drafts[index]) && <Check className="size-3 text-status-success" />}
                                {value.header}
                            </button>
                        ))}
                    </div>
                ) : (
                    <span className="flex-1 text-xs font-medium text-muted-foreground">{item.header}</span>
                )}
                {items.length > 1 && (
                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                        {current + 1}/{items.length}
                    </span>
                )}
            </div>

            <div className="space-y-3 px-4 py-3">
                <div className="space-y-0.5">
                    <p className="text-sm font-medium">{item.question}</p>
                    {item.multi_select && <p className="text-xs text-muted-foreground">Pode escolher várias.</p>}
                </div>

                <div role={item.multi_select ? 'group' : 'radiogroup'} className="flex flex-col gap-1.5">
                    {item.options.map((option, index) => {
                        const selected = draft.selected.includes(option.label);

                        return (
                            <button
                                key={option.label}
                                type="button"
                                role={item.multi_select ? 'checkbox' : 'radio'}
                                aria-checked={selected}
                                disabled={disabled}
                                onClick={() => choose(option.label)}
                                className={cn(
                                    'group flex w-full items-start gap-3 rounded-lg border px-3 py-2 text-left transition-colors disabled:cursor-default',
                                    selected ? 'border-primary bg-primary/5' : 'hover:border-foreground/20 hover:bg-accent/50',
                                )}
                            >
                                <Marker index={index} selected={selected} multi={item.multi_select} />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">{option.label}</span>
                                    {option.description && <span className="block text-xs text-muted-foreground">{option.description}</span>}
                                </span>
                            </button>
                        );
                    })}

                    <div
                        className={cn(
                            'rounded-lg border transition-colors',
                            draft.other !== null ? 'border-primary bg-primary/5' : 'border-dashed hover:border-foreground/20',
                        )}
                    >
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={chooseOther}
                            className="flex w-full items-center gap-3 px-3 py-2 text-left disabled:cursor-default"
                        >
                            <span className="grid size-5 shrink-0 place-content-center rounded-md border text-muted-foreground">
                                <PenLine className="size-3" />
                            </span>
                            <span className="text-sm font-medium">Outra</span>
                            <span className="text-xs text-muted-foreground">Escrever a minha resposta</span>
                        </button>
                        {draft.other !== null && (
                            <div className="px-3 pb-2">
                                <Textarea
                                    rows={2}
                                    autoFocus
                                    value={draft.other}
                                    disabled={disabled}
                                    onChange={(event) => update(current, { ...draft, other: event.target.value })}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && !event.shiftKey) {
                                            event.preventDefault();
                                            if (last) {
                                                submit();
                                            } else if (answered(draft)) {
                                                setCurrent(current + 1);
                                            }
                                        }
                                    }}
                                    placeholder="A sua resposta…"
                                    className="min-h-14 resize-none bg-background text-sm"
                                />
                            </div>
                        )}
                    </div>
                </div>

                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>

            <div className="flex items-center gap-2 border-t px-4 py-2.5">
                {question.can_answer ? (
                    <span className="hidden flex-1 text-[11px] text-muted-foreground sm:block">Teclas 1–{item.options.length} para escolher</span>
                ) : (
                    <span className="flex-1 text-xs text-muted-foreground">À espera da resposta da pessoa a quem perguntou.</span>
                )}
                <span className="flex-1 sm:hidden" />
                {current > 0 && (
                    <Button size="sm" variant="ghost" onClick={() => setCurrent(current - 1)}>
                        Anterior
                    </Button>
                )}
                {!last && (
                    <Button size="sm" variant="outline" disabled={!answered(draft)} onClick={() => setCurrent(current + 1)}>
                        Seguinte
                    </Button>
                )}
                {question.can_answer && (last || complete) && (
                    <Button size="sm" disabled={!complete || sending} onClick={submit}>
                        {items.length > 1 ? 'Enviar respostas' : 'Responder'}
                        <CornerDownLeft className="size-3.5 opacity-70" />
                    </Button>
                )}
            </div>
        </div>
    );
}

function Marker({ index, selected, multi }: { index: number; selected: boolean; multi: boolean }) {
    return (
        <span
            className={cn(
                'mt-0.5 grid size-5 shrink-0 place-content-center border text-[11px] font-medium tabular-nums transition-colors',
                multi ? 'rounded-md' : 'rounded-full',
                selected ? 'border-primary bg-primary text-primary-foreground' : 'text-muted-foreground group-hover:border-foreground/30',
            )}
        >
            {selected ? <Check className="size-3" /> : index + 1}
        </span>
    );
}

function AnsweredCard({ question, answers }: { question: AgentQuestions; answers: AgentQuestionAnswer[] }) {
    return (
        <div className="w-full max-w-xl rounded-xl border bg-background/60 px-4 py-3 text-sm">
            <ul className="flex flex-col gap-2">
                {question.questions.map((item, index) => {
                    const answer = answers[index];

                    return (
                        <li key={item.question} className="space-y-1">
                            <p className="text-xs text-muted-foreground">
                                <span className="font-medium text-foreground/80">{item.header}</span> · {item.question}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {answer?.selected.map((label) => (
                                    <span
                                        key={label}
                                        className="inline-flex h-6 items-center gap-1 rounded-full bg-primary/10 px-2.5 text-xs font-medium text-primary"
                                    >
                                        <Check className="size-3" />
                                        {label}
                                    </span>
                                ))}
                                {answer?.other && (
                                    <span className="inline-flex min-h-6 items-center rounded-full bg-muted px-2.5 py-0.5 text-xs">
                                        «{answer.other}»
                                    </span>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            {question.answered_by && question.answered_at && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Respondido por {question.answered_by} às {time(question.answered_at)}
                </p>
            )}
        </div>
    );
}
