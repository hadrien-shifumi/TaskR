import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { AvatarStack, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ArrowLeft, ArchiveRestore } from 'lucide-react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatShortDate } from '@/lib/format-date';

interface ArchivedIssue {
    id: string;
    title: string;
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    assignees: { id: number; name: string }[];
    labels: { id: string; name: string; color: string }[];
    archivedAt: string;
}

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    issues: ArchivedIssue[];
    canUnarchive: boolean;
};

/** The archive of a project: read-only history, unarchiving is reserved
 * to the workspace owner (the toggle cascades back to the subtree). */
export default function Archives() {
    const { project, issues, canUnarchive } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const unarchive = (issueId: string) => {
        router.post(route('issues.archive', issueId));
    };

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectArchives.backToBoard')}
                    onClick={() => router.get(route('projects.board', project.id))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={<span>{t('projectArchives.title', { name: project.name })}</span>}
            actions={
                <>
                    <ProjectViewSwitcher projectId={project.id} active="board" />
                    <ProjectMenu project={project} issuesCount={0} />
                </>
            }
        >
            <Head title={t('projectArchives.headTitle', { name: project.name })} />

            <p className="mb-4 text-sm text-muted-foreground">{t('projectArchives.intro')}</p>

            <FlashBanner />

            {issues.length === 0 ? (
                <p
                    className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
                    data-test="archives-empty"
                >
                    {t('projectArchives.empty')}
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('projectArchives.issue')}</TableHead>
                            <TableHead>{t('projectArchives.status')}</TableHead>
                            <TableHead>{t('projectArchives.priority')}</TableHead>
                            <TableHead>{t('projectArchives.assignees')}</TableHead>
                            <TableHead>{t('projectArchives.archivedAt')}</TableHead>
                            {canUnarchive ? <TableHead className="w-0" /> : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {issues.map((issue) => (
                            <TableRow key={issue.id}>
                                <TableCell>
                                    <Link
                                        href={route('issues.show', issue.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {issue.title}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <StatusBadge name={issue.status.name} category={issue.status.category} />
                                </TableCell>
                                <TableCell>
                                    <PriorityBadge priority={issue.priority} />
                                </TableCell>
                                <TableCell>
                                    <AvatarStack people={issue.assignees} />
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {formatShortDate(issue.archivedAt)}
                                </TableCell>
                                {canUnarchive ? (
                                    <TableCell>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            data-test={`archive-restore-${issue.id}`}
                                            onClick={() => unarchive(issue.id)}
                                        >
                                            <ArchiveRestore className="size-3.5" aria-hidden />
                                            {t('projectArchives.unarchive')}
                                        </Button>
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </AppLayout>
    );
}
