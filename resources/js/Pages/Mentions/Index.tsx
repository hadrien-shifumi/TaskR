import { Avatar } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';

type MentionItem = {
    id: string;
    type: 'user' | 'project';
    commentId: string;
    authorName: string;
    issueId: string;
    issueTitle: string;
    excerpt: string;
    readAt: string | null;
    createdAt: string | null;
};

type PageProps = {
    mentions: MentionItem[];
    unreadCount: number;
};

/** Relative time label of a mention ("just now", "5 min ago"…). */
function mentionTime(date: string, t: TFunction): string {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(date).getTime()) / 1000));
    if (seconds < 60) return t('mentions.time.justNow');
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return t('mentions.time.minAgo', { count: minutes });
    const hours = Math.round(minutes / 60);
    if (hours < 24) return t('mentions.time.hoursAgo', { count: hours });
    const days = Math.round(hours / 24);
    if (days < 30) return t('mentions.time.daysAgo', { count: days });
    return t('mentions.time.onDate', { date: new Date(date).toLocaleDateString() });
}

/**
 * Mentions of the account: unread first, then recently read ones. Clicking
 * a row marks it read server-side and lands on the issue it was written on.
 */
export default function Index() {
    const { mentions, unreadCount } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const openMention = (mentionId: string) => {
        router.post(route('mentions.read', mentionId));
    };

    const markAllRead = () => {
        router.post(route('mentions.read-all'));
    };

    return (
        <AppLayout
            title={t('mentions.title')}
            subtitle={t('mentions.subtitle')}
            actions={
                unreadCount > 0 ? (
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="mentions-read-all"
                        onClick={markAllRead}
                    >
                        <CheckCheck aria-hidden />
                        {t('mentions.markAllRead')}
                    </Button>
                ) : null
            }
        >
            <Head title={t('mentions.title')} />

            {mentions.length === 0 ? (
                <div className="rounded-xl border bg-card p-8 text-center" data-test="mentions-empty">
                    <p className="text-sm font-medium">{t('mentions.empty')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">{t('mentions.emptyHint')}</p>
                </div>
            ) : (
                <ul className="divide-y rounded-xl border bg-card" data-test="mentions-list">
                    {mentions.map((mention) => (
                        <li key={mention.id}>
                            <button
                                type="button"
                                data-test="mention-row"
                                data-unread={mention.readAt === null ? 'true' : undefined}
                                onClick={() => openMention(mention.id)}
                                className="flex w-full items-start gap-3 p-4 text-left transition-colors hover:bg-secondary/60"
                            >
                                <Avatar name={mention.authorName} />
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="text-[13px] font-medium">{mention.authorName}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {t('mentions.mentionedYou')}
                                        </span>
                                        <span className="text-[13px] font-medium text-primary">
                                            {mention.issueTitle}
                                        </span>
                                        {mention.readAt === null ? (
                                            <Badge data-test="mention-unread-badge">{t('mentions.unread')}</Badge>
                                        ) : null}
                                    </span>
                                    <span className="mt-1 line-clamp-2 block text-[13.5px] text-muted-foreground">
                                        {mention.excerpt}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {mention.createdAt ? mentionTime(mention.createdAt, t) : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
