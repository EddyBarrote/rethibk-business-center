import { Head } from '@inertiajs/react';

import { type SkillData, SkillEditor, type SkillFileRow } from '@/Components/catalog/SkillEditor';
import { PageHeader } from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';

export default function SkillForm({ skill, files }: { skill: SkillData | null; files: SkillFileRow[] }) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Skills', href: '/skills' }, { label: skill ? skill.name : 'Nova skill' }]}>
            <Head title={skill ? skill.name : 'Nova skill'} />
            <PageHeader
                title={skill ? skill.name : 'Nova skill'}
                description="Como as Agent Skills do Claude: um nome, quando se aplica, instruções em markdown e ficheiros de apoio."
            />
            <SkillEditor base="/skills" skill={skill} files={files} cancelHref="/skills" switchField="is_enabled" />
        </AppLayout>
    );
}
