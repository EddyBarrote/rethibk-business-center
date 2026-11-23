import { Head } from '@inertiajs/react';

import { type SkillVersionDetail, type SkillVersionDiff, SkillVersionView } from '@/Components/catalog/SkillVersions';
import { PageHeader } from '@/Components/PageHeader';
import SettingsLayout from '@/Layouts/SettingsLayout';

/** One version of a company skill, compared with the current one (docs/DECISOES.md, "Latência, taxa de erro e versões das skills"). */
export default function SkillVersion({
    skill,
    version,
    diff,
}: {
    skill: { id: number; key: string; name: string };
    version: SkillVersionDetail;
    diff: SkillVersionDiff;
}) {
    return (
        <SettingsLayout crumbs={[{ label: skill.name, href: `/settings/skills/${skill.id}/edit` }, { label: `Versão ${version.number}` }]}>
            <Head title={`${skill.name} · versão ${version.number}`} />
            <PageHeader
                title={`${skill.name} · versão ${version.number}`}
                description="O que a skill tinha nesta versão e o que mudou desde então. Repor cria uma versão nova; o histórico fica todo."
            />
            <SkillVersionView base="/settings/skills" skill={skill} version={version} diff={diff} />
        </SettingsLayout>
    );
}
