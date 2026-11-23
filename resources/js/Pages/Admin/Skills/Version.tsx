import { type SkillVersionDetail, type SkillVersionDiff, SkillVersionView } from '@/Components/catalog/SkillVersions';
import { PageHeader } from '@/Components/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';

/** One version of a global skill, compared with the current one. Restoring reaches every organisation that uses it. */
export default function PlatformSkillVersion({
    skill,
    version,
    diff,
}: {
    skill: { id: number; key: string; name: string };
    version: SkillVersionDetail;
    diff: SkillVersionDiff;
}) {
    return (
        <AdminLayout
            title={`${skill.name} · versão ${version.number}`}
            breadcrumbs={[
                { label: 'Skills globais', href: '/skills' },
                { label: skill.name, href: `/skills/${skill.id}/edit` },
                { label: `Versão ${version.number}` },
            ]}
        >
            <PageHeader
                title={`${skill.name} · versão ${version.number}`}
                description="O que a skill global tinha nesta versão e o que mudou desde então. Repor cria uma versão nova, que chega de imediato a todas as organizações que a usam."
            />
            <SkillVersionView base="/skills" skill={skill} version={version} diff={diff} />
        </AdminLayout>
    );
}
