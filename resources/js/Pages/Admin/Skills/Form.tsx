import { type SkillData, SkillEditor, type SkillFileRow } from '@/Components/catalog/SkillEditor';
import { PageHeader } from '@/Components/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';

export default function PlatformSkillForm({ skill, files }: { skill: SkillData | null; files: SkillFileRow[] }) {
    return (
        <AdminLayout title={skill ? skill.name : 'Nova skill global'} breadcrumbs={[{ label: 'Skills globais', href: '/skills' }, { label: skill ? skill.name : 'Nova' }]} wide>
            <PageHeader
                title={skill ? skill.name : 'Nova skill global'}
                description="Oferecida a todas as organizações; cada uma activa as que quer. Uma alteração chega a todas de imediato."
            />
            <SkillEditor base="/skills" skill={skill} files={files} cancelHref="/skills" switchField="is_active" />
        </AdminLayout>
    );
}
