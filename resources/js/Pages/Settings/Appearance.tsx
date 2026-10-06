import { Head } from '@inertiajs/react';
import { Check, type LucideIcon, Monitor, Moon, Sun } from 'lucide-react';

import { PageHeader } from '@/Components/PageHeader';
import { SettingsBlock } from '@/Components/SettingsBlock';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { type Appearance, useAppearance } from '@/lib/appearance';
import { cn } from '@/lib/utils';

const options: { value: Appearance; label: string; hint: string; icon: LucideIcon }[] = [
    { value: 'light', label: 'Claro', hint: 'Fundo creme, para o dia.', icon: Sun },
    { value: 'dark', label: 'Escuro', hint: 'Fundo escuro, cansa menos à noite.', icon: Moon },
    { value: 'system', label: 'Sistema', hint: 'Segue o que o computador ou o telemóvel usa.', icon: Monitor },
];

/** A small picture of the console in each theme: sidebar, title and two rows. */
function Preview({ tone }: { tone: Appearance }) {
    const pane = (dark: boolean, className?: string) => (
        <div className={cn('flex h-full gap-1.5 p-2', dark ? 'bg-[#2b2a27]' : 'bg-[#f7f5ef]', className)}>
            <div className={cn('w-1/4 rounded-sm', dark ? 'bg-[#383631]' : 'bg-[#ebe7dc]')} />
            <div className="flex flex-1 flex-col gap-1.5">
                <div className={cn('h-2 w-1/2 rounded-sm', dark ? 'bg-[#d9d4c7]' : 'bg-[#4a463c]')} />
                <div className={cn('h-2 rounded-sm', dark ? 'bg-[#45423b]' : 'bg-[#e4dfd2]')} />
                <div className={cn('h-2 w-3/4 rounded-sm', dark ? 'bg-[#45423b]' : 'bg-[#e4dfd2]')} />
                <div className="mt-auto h-2 w-1/3 self-end rounded-sm bg-[#bb5735]" />
            </div>
        </div>
    );

    return (
        <div className="relative aspect-[16/10] overflow-hidden rounded-lg border">
            {tone === 'system' ? (
                <div className="grid h-full grid-cols-2">
                    {pane(false)}
                    {pane(true)}
                </div>
            ) : (
                pane(tone === 'dark')
            )}
        </div>
    );
}

/** A minha conta › Aparência: light, dark or the system's, kept in this browser. */
export default function AppearancePage() {
    const { appearance, setAppearance } = useAppearance();

    return (
        <SettingsLayout>
            <Head title="Aparência" />
            <PageHeader title="Aparência" description="O tema da plataforma neste browser. Muda logo, sem guardar." />

            <SettingsBlock title="Tema" description="Cada browser guarda a sua escolha: pode usar o claro no computador e o escuro no telemóvel.">
                <div role="radiogroup" aria-label="Tema" className="grid gap-3 sm:grid-cols-3">
                    {options.map((option) => {
                        const selected = appearance === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                onClick={() => setAppearance(option.value)}
                                className={cn(
                                    'flex flex-col gap-3 rounded-xl border bg-background p-3 text-left transition-colors hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                    selected && 'border-primary ring-1 ring-primary',
                                )}
                            >
                                <Preview tone={option.value} />
                                <span className="flex items-start gap-2">
                                    <option.icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium">{option.label}</span>
                                        <span className="block text-xs text-muted-foreground">{option.hint}</span>
                                    </span>
                                    {selected && <Check className="size-4 shrink-0 text-primary" />}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </SettingsBlock>
        </SettingsLayout>
    );
}
