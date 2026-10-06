import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

import { ListPanel, Section } from '@/Components/Blocks';
import AppLayout from '@/Layouts/AppLayout';
import { SettingsHeading } from '@/Layouts/SettingsLayout';
import { visibleSettings } from '@/lib/settings';
import type { SharedProps } from '@/types';

/** Definições: the sections this person may open, grouped. On a phone this list is how people move between them. */
export default function SettingsIndex() {
    const { auth } = usePage<SharedProps>().props;
    const groups = visibleSettings(auth.user?.permissions);

    return (
        <AppLayout breadcrumbs={[{ label: 'Definições' }]}>
            <Head title="Definições" />
            <SettingsHeading />

            <div className="grid min-w-0 gap-8 lg:grid-cols-2 lg:gap-x-10">
                {groups.map((group) => (
                    <Section key={group.label} title={group.label}>
                        <ListPanel>
                            {group.items.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <item.icon className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium">{item.label}</span>
                                        <span className="block text-xs text-muted-foreground max-sm:hidden">{item.description}</span>
                                    </span>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                </Link>
                            ))}
                        </ListPanel>
                    </Section>
                ))}
            </div>
        </AppLayout>
    );
}
