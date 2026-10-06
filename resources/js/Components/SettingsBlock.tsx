import type { ReactNode } from 'react';

/** Settings row: what the group is about on the left, its controls in a bordered block on the right. */
export function SettingsBlock({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-4 lg:grid-cols-[16rem_1fr] lg:gap-8">
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            <div className="flex min-w-0 flex-col gap-5 rounded-xl border bg-card p-5">{children}</div>
        </section>
    );
}
