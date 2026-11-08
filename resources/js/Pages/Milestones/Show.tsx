import { MilestoneFormDialog } from '@/components/milestone-form-dialog';
import { MilestoneDeleteDialog } from '@/components/milestone-delete-dialog';
import { MilestoneSetParentDialog } from '@/components/milestone-set-parent-dialog';
import { MilestoneStateBadge, MilestoneWindow, milestoneState } from '@/components/milestone-badges';
import { FlashBanner } from '@/components/flash-banner';
import { PriorityBadge, StatusBadge } from '@/components/issue-badges';
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
import { formatDate } from '@/lib/format-date';
import { ArrowLeft, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { MilestoneCard, MilestoneOption } from '@/types';
import type { IssueRow } from '@/Pages/Issues/Index/types';

type MilestoneDetail = MilestoneCard & {
    parent: { id: string; title: string } | null;
    children: MilestoneCard[];
    issues: IssueRow[];
};

type PageProps = {
    milestone: MilestoneDetail;
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    parentCandidates: MilestoneOption[];
    canContribute: boolean;
    canManage: boolean;
};

/**
 * Detail page of a milestone: the fiche (dates, state, progression),
 * the attached issues with their status, and the sub-milestones. The
 * action menu mirrors the Échéances tab — edition, close and parent
 * for every project member, deletion for the managers only.
 */
export default function Show() {
    const { milestone, project, parentCandidates, canContribute, canManage } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState<MilestoneCard | null>(null);
    const [settingParent, setSettingParent] = useState(false);

    const state = milestoneState(milestone);
    const completion =
        milestone.totalIssues > 0 ? Math.round((milestone.doneIssues / milestone.totalIssues) * 100) : 0;

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('milestones.backToMilestones')}
                    onClick={() => router.get(route('projects.milestones', project.id))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={
                <>
                    <span>{milestone.title}</span>
                    <MilestoneStateBadge state={state} />
                </>
            }
            subtitle={project.name}
            actions={
                canContribute || canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            aria-label={t('milestones.actionsFor', { name: milestone.title })}
                            data-test="milestone-actions"
                            className="grid size-7 place-items-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                        >
                            <MoreHorizontal className="size-4" aria-hidden />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuGroup>
                                {canContribute ? (
                                    <>
                                        <DropdownMenuItem
                                            data-test="milestone-edit"
                                            onClick={() => setEditing(true)}
                                        >
                                            {t('common.edit')}
                                        </DropdownMenuItem>
                                        {milestone.completedAt === null ? (
                                            <DropdownMenuItem
                                                data-test="milestone-close"
                                                onClick={() => router.post(route('milestones.close', milestone.id))}
                                            >
                                                {t('milestones.close')}
                                            </DropdownMenuItem>
                                        ) : null}
                                        <DropdownMenuItem
                                            data-test="milestone-set-parent"
                                            onClick={() => setSettingParent(true)}
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
                ) : null
            }
        >
            <Head title={t('milestones.detailHeadTitle', { title: milestone.title })} />

            <FlashBanner />

            <div className="mb-7 grid gap-2 border-b pb-6">
                <div className="flex flex-wrap items-center gap-3">
                    <MilestoneWindow milestone={milestone} />
                    {milestone.completedAt !== null ? (
                        <span className="text-xs text-muted-foreground">
                            {t('milestones.closedOn', { date: formatDate(milestone.completedAt) })}
                        </span>
                    ) : null}
                    {milestone.creatorName ? (
                        <span className="text-xs text-muted-foreground">
                            {t('milestones.createdBy', { name: milestone.creatorName })}
                        </span>
                    ) : null}
                </div>
                {milestone.description ? (
                    <p className="max-w-[62ch] text-[15px] text-muted-foreground">{milestone.description}</p>
                ) : null}
                {milestone.parent !== null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('milestones.parentTitle')}{' '}
                        <Link
                            href={route('milestones.show', milestone.parent.id)}
                            className="text-primary hover:underline"
                        >
                            {milestone.parent.title}
                        </Link>
                    </p>
                ) : null}
                <div data-test="milestone-progress" className="flex items-center gap-3">
                    <Progress value={completion} color={state === 'overdue' ? 'destructive' : 'success'} className="w-40" />
                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                        {t('milestones.progress', { done: milestone.doneIssues, total: milestone.totalIssues })}
                    </span>
                </div>
            </div>

            <div className="grid items-start gap-7 lg:grid-cols-[1fr_320px]">
                <section>
                    <h2 className="mb-3 text-base font-semibold">{t('milestones.issues')}</h2>
                    {milestone.issues.length > 0 ? (
                        <ul className="overflow-hidden rounded-xl border bg-card divide-y" data-test="milestone-issues">
                            {milestone.issues.map((issue) => (
                                <li key={issue.id}>
                                    <Link
                                        href={route('issues.show', issue.id)}
                                        className="flex flex-wrap items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-accent/40"
                                    >
                                        <span className="min-w-0 flex-1 truncate font-medium">{issue.title}</span>
                                        <PriorityBadge priority={issue.priority} />
                                        <StatusBadge name={issue.status.name} category={issue.status.category} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground" data-test="milestone-no-issues">
                            {t('milestones.noIssues')}
                        </p>
                    )}
                </section>

                <section>
                    <h2 className="mb-3 text-base font-semibold">{t('milestones.children')}</h2>
                    {milestone.children.length > 0 ? (
                        <ul className="overflow-hidden rounded-xl border bg-card divide-y" data-test="milestone-children">
                            {milestone.children.map((child) => (
                                <li key={child.id} className="flex items-center gap-2.5 px-4 py-2.5">
                                    <Link
                                        href={route('milestones.show', child.id)}
                                        className="min-w-0 flex-1 truncate text-sm font-medium hover:text-primary"
                                    >
                                        {child.title}
                                    </Link>
                                    <MilestoneStateBadge state={milestoneState(child)} />
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground" data-test="milestone-no-children">
                            {t('milestones.noChildren')}
                        </p>
                    )}
                </section>
            </div>

            <MilestoneFormDialog
                open={editing}
                onClose={() => setEditing(false)}
                project={{ id: project.id, name: project.name }}
                milestone={milestone}
            />
            <MilestoneDeleteDialog milestone={deleting} onClose={() => setDeleting(null)} />
            <MilestoneSetParentDialog
                milestone={milestone}
                candidates={parentCandidates}
                open={settingParent}
                onClose={() => setSettingParent(false)}
            />
        </AppLayout>
    );
}
