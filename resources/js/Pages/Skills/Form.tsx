import { Head } from '@inertiajs/react';

import { type SkillData, SkillEditor, type SkillFileRow } from '@/Components/catalog/SkillEditor';
import { PageHeader } from '@/Components/PageHeader';
import SettingsLayout from '@/Layouts/SettingsLayout';

export default function SkillForm({ skill, files }: { skill: SkillData | null; files: SkillFileRow[] }) {
    return (
        <SettingsLayout crumbs={[{ label: skill ? skill.name : 'Nova skill' }]}>
            <Head title={skill ? skill.name : 'Nova skill'} />
            <PageHeader
                title={skill ? skill.name : 'Nova skill'}
                description="Como as Agent Skills do Claude: um nome, quando se aplica, instruções em markdown e ficheiros de apoio."
            />
            <SkillEditor base="/settings/skills" skill={skill} files={files} cancelHref="/settings/skills" switchField="is_enabled" />
        </SettingsLayout>
    );
}
