import { MilestoneFormDialog } from '@/components/milestone-form-dialog';
import { MilestoneDeleteDialog } from '@/components/milestone-delete-dialog';
import { MilestoneSetParentDialog } from '@/components/milestone-set-parent-dialog';
import { MilestoneStateBadge, MilestoneWindow, milestoneState, milestoneTree } from '@/components/milestone-badges';
import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { FlashBanner } from '@/components/flash-banner';
import { Progress } from '@/components/progress';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { MilestoneCard, MilestoneOption } from '@/types';

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    milestones: MilestoneCard[];
    canContribute: boolean;
    canManage: boolean;
};

const MENU_TRIGGER_CLASS =
    'grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground';

/**
 * Échéances tab of a project: the milestone list rebuilt as an
 * indented tree (parent → children), each row carrying its date
 * window, its state, its issue progression and the roster-based
 * actions — creation, edition, close and parent for every project
 * member, deletion for the managers only.
 */
export default function Milestones() {
    const { project, milestones, canContribute, canManage } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<MilestoneCard | null>(null);
    const [deleting, setDeleting] = useState<MilestoneCard | null>(null);
    const [settingParent, setSettingParent] = useState<MilestoneCard | null>(null);

    const rows = milestoneTree(milestones);

    const close = (milestone: MilestoneCard) => {
        router.post(route('milestones.close', milestone.id));
    };

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectBoard.backToProjects')}
                    onClick={() => router.get(route('projects.index'))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={project.name}
            actions={
                <>
                    <ProjectViewSwitcher projectId={project.id} active="milestones" />
                    <ProjectMenu project={project} issuesCount={0} />
                    {canContribute ? (
                        <Button size="sm" data-test="milestone-create-open" onClick={() => setCreating(true)}>
                            <Plus className="size-3.5" aria-hidden />
                            {t('milestones.create')}
                        </Button>
                    ) : null}
                </>
            }
        >
            <Head title={t('milestones.headTitle', { name: project.name })} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">{t('milestones.intro')}</p>

            <FlashBanner />

            <div className="overflow-hidden rounded-xl border bg-card" data-test="milestones-list">
                {rows.length > 0 ? (
                    <ul className="divide-y">
                        {rows.map(({ milestone, depth }) => (
                            <li
                                key={milestone.id}
                                data-test="milestone-row"
                                className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3 hover:bg-accent/40"
                                style={{ paddingLeft: depth > 0 ? `${1 + depth * 1.75}rem` : undefined }}
                            >
                                <Link
                                    href={route('milestones.show', milestone.id)}
                                    className="min-w-0 flex-1 truncate text-sm font-medium hover:text-primary"
                                    data-test="milestone-title-link"
                                >
                                    {milestone.title}
                                </Link>
                                <MilestoneStateBadge state={milestoneState(milestone)} />
                                <MilestoneWindow milestone={milestone} />
                                <span
                                    data-test="milestone-progress"
                                    className="flex items-center gap-2 font-mono text-xs text-muted-foreground tabular-nums"
                                >
                                    <Progress
                                        value={
                                            milestone.totalIssues > 0
                                                ? Math.round((milestone.doneIssues / milestone.totalIssues) * 100)
                                                : 0
                                        }
                                        color={milestoneState(milestone) === 'overdue' ? 'destructive' : 'success'}
                                        className="w-16"
                                    />
                                    {t('milestones.progress', {
                                        done: milestone.doneIssues,
                                        total: milestone.totalIssues,
                                    })}
                                </span>
                                {milestone.creatorName ? (
                                    <span className="hidden text-xs text-muted-foreground sm:inline">
                                        {t('milestones.createdBy', { name: milestone.creatorName })}
                                    </span>
                                ) : null}
                                {canContribute || canManage ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            aria-label={t('milestones.actionsFor', { name: milestone.title })}
                                            data-test="milestone-actions"
                                            className={MENU_TRIGGER_CLASS}
                                        >
                                            <MoreHorizontal className="size-4" aria-hidden />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuGroup>
                                                {canContribute ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            data-test="milestone-edit"
                                                            onClick={() => setEditing(milestone)}
                                                        >
                                                            {t('common.edit')}
                                                        </DropdownMenuItem>
                                                        {milestone.completedAt === null ? (
                                                            <DropdownMenuItem
                                                                data-test="milestone-close"
                                                                onClick={() => close(milestone)}
                                                            >
                                                                {t('milestones.close')}
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        <DropdownMenuItem
                                                            data-test="milestone-set-parent"
                                                            onClick={() => setSettingParent(milestone)}
                                                        >
                                                            {t('milestones.setParent')}
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : null}
                                                {canManage ? (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            data-test="milestone-delete"
                                                            onClick={() => setDeleting(milestone)}
                                                        >
                                                            {t('common.delete')}
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : null}
                                            </DropdownMenuGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="px-5 py-6 text-center text-sm text-muted-foreground" data-test="milestones-empty">
                        {t('milestones.empty')}
                    </p>
                )}
            </div>

            <MilestoneFormDialog
                open={creating}
                onClose={() => setCreating(false)}
                project={{ id: project.id, name: project.name }}
            />
            <MilestoneFormDialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                project={{ id: project.id, name: project.name }}
                milestone={editing}
            />
            <MilestoneDeleteDialog milestone={deleting} onClose={() => setDeleting(null)} />
            {settingParent !== null ? (
                <MilestoneSetParentDialog
                    milestone={settingParent}
                    candidates={parentCandidates(milestones, settingParent.id)}
                    open
                    onClose={() => setSettingParent(null)}
                />
            ) : null}
        </AppLayout>
    );
}

/** Same-project candidates minus the milestone itself — cycles stay the action's job. */
function parentCandidates(milestones: MilestoneCard[], milestoneId: string): MilestoneOption[] {
    return milestones
        .filter((milestone) => milestone.id !== milestoneId)
        .map((milestone) => ({ id: milestone.id, title: milestone.title }));
}
