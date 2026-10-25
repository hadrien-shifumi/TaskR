import { Banner } from '@/components/banner';
import { FlashBanner } from '@/components/flash-banner';
import { AvatarStack, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArchiveRestore, Inbox, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, PageSharedProps, UserRef } from '@/types';
import { formatDate } from '@/lib/format-date';

type ArchivedIssue = {
    id: string;
    title: string;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    assignees: { id: number; name: string }[];
    labels: LabelRef[];
    archivedAt: string;
};

type PageProps = {
    project: { id: string; name: string };
    issues: ArchivedIssue[];
    isOwner: boolean;
};

export default function Archives() {
    const { project, issues, isOwner } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const owner = isOwner || workspace?.role === 'owner';

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
        >
            <Head title={t('projectArchives.headTitle', { name: project.name })} />

            <FlashBanner />

            <p className="mb-4 text-sm text-muted-foreground">
                {t('projectArchives.intro', { count: issues.length })}
            </p>

            {issues.length === 0 ? (
                <Banner variant="info" icon={Inbox as LucideIcon}>
                    {t('projectArchives.empty')}
                </Banner>
            ) : (
                <ul className="divide-y">
                    {issues.map((issue) => (
                        <li
                            key={issue.id}
                            data-test="archive-row"
                            className="flex flex-wrap items-center gap-3 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <a
                                    href={route('issues.show', issue.id)}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {issue.title}
                                </a>
                                <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <StatusBadge name={issue.status.name} category={issue.status.category} />
                                    <PriorityBadge priority={issue.priority} />
                                    <span>
                                        {t('projectArchives.archivedOn', {
                                            date: formatDate(issue.archivedAt),
                                        })}
                                    </span>
                                </div>
                            </div>
                            <AvatarStack people={issue.assignees} />
                            {owner ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    data-test="unarchive-issue"
                                    onClick={() => unarchive(issue.id)}
                                >
                                    <ArchiveRestore className="size-3.5" aria-hidden />
                                    {t('projectArchives.unarchive')}
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
