import { Button } from '@/components/ui/button';
import { MarkdownView } from '@/components/ui/markdown-editor';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ActivityChange, ActivityEvent } from '../types';
import { scrollToComment } from './comment-anchor';
import { formatDate } from './format';

/** Simple emoji per journalled event type; unknown types get a plain bullet. */
const typeIcons: Record<string, string> = {
    'issue.created': '✨',
    'issue.updated': '✏️',
    'issue.deleted': '🗑️',
    'issue.status_changed': '🔀',
    'issue.priority_changed': '⚡',
    'issue.assigned': '👤',
    'issue.unassigned': '👋',
    'issue.due_date_changed': '📅',
    'issue.estimate_changed': '⏱️',
    'issue.parent_set': '🔗',
    'issue.parent_removed': '✂️',
    'issue.archived': '📦',
    'issue.restored': '♻️',
    'comment.created': '💬',
    'comment.updated': '🗨️',
    'comment.deleted': '🗑️',
    'time_entry.created': '⏱️',
    'time_entry.updated': '🕒',
    'time_entry.deleted': '🗑️',
    'project.created': '📁',
    'project.updated': '✏️',
    'project.archived': '📦',
    'project.unarchived': '📂',
    'project.deleted': '🗑️',
    'knowledge_page.created': '📄',
    'knowledge_page.updated': '✏️',
    'knowledge_page.deleted': '🗑️',
    'knowledge_page.restored': '♻️',
};

/**
 * Metadata keys journalled alongside the real field diffs. They identify the
 * event's target (an id, a counter) — never something the user changed — so
 * they are not rendered as `field before → after` lines.
 */
const technicalFields = new Set(['comment_id', 'time_entry_id', 'comments_count', 'time_entries_count']);

/** Comment events whose body is reachable through the comments thread. */
const commentTypes = new Set(['comment.created', 'comment.updated', 'comment.deleted']);

/** Null or emptied values render as the localized "empty" marker. */
function formatChangeValue(value: unknown, emptyLabel: string): string {
    if (value === null || value === undefined || value === '') {
        return emptyLabel;
    }

    return String(value);
}

/** A before → after pair, as opposed to a flat added/removed list. */
function isChangePair(value: unknown): value is ActivityChange {
    return typeof value === 'object' && value !== null && 'before' in value && 'after' in value;
}

/** "1 Nov 2026 at 14:32" — the timeline needs the time, not only the day. */
function formatDateTime(date: string | null): string {
    if (!date) return '';
    const parsed = new Date(date);
    const time = parsed.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

    return `${formatDate(date)} · ${time}`;
}

type ActivityEventRowProps = {
    event: ActivityEvent;
};

/**
 * One timeline line: emoji by type, i18n type label, actor name (or
 * "System"), formatted date and the field-by-field before → after changes.
 * Comment events carry no body: a live comment is reached through its anchor
 * in the thread, a deleted one expands the journal snapshot.
 * Shared between the issue page and the board's issue modal.
 */
export function ActivityEventRow({ event }: ActivityEventRowProps) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const actorName = event.actor?.name ?? t('audit.system');

    const isComment = commentTypes.has(event.type);
    const snapshot = isComment ? (event.changes.body as ActivityChange | undefined)?.before : undefined;
    const hasSnapshot = typeof snapshot === 'string' && snapshot !== '';

    const changes = Object.entries(event.changes).filter(
        ([field]) => !technicalFields.has(field) && !(isComment && field === 'body'),
    );

    const toggleComment = () => {
        if (!expanded && event.commentId !== null && scrollToComment(event.commentId)) {
            // The comment is on the page: scroll and flash it instead of
            // duplicating its body in the timeline.
            return;
        }

        setExpanded((value) => !value);
    };

    return (
        <li data-test="activity-event-row" className="flex gap-3 py-3.5">
            <span aria-hidden className="mt-0.5 text-base leading-none">
                {typeIcons[event.type] ?? '•'}
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <span data-test="activity-event-type" className="text-[13px] font-medium">
                        {t(`audit.types.${event.type}`)}
                    </span>
                    <span data-test="activity-event-actor" className="text-[13px] text-muted-foreground">{actorName}</span>
                    <span data-test="activity-event-date" className="text-xs text-muted-foreground">
                        {formatDateTime(event.createdAt)}
                    </span>
                </div>
                {isComment && (event.commentId !== null || hasSnapshot) ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="xs"
                        data-test="activity-comment-toggle"
                        aria-expanded={expanded}
                        className="mt-1 -ml-2 text-xs text-muted-foreground"
                        onClick={toggleComment}
                    >
                        {expanded ? <ChevronDown aria-hidden /> : <ChevronRight aria-hidden />}
                        {expanded ? t('audit.hideComment') : t('audit.viewComment')}
                    </Button>
                ) : null}
                {isComment && expanded && hasSnapshot ? (
                    <div
                        data-test="activity-comment-snapshot"
                        className="mt-1.5 rounded-md border bg-muted/40 px-3 py-2"
                    >
                        <MarkdownView value={snapshot} className="text-[13px]" />
                    </div>
                ) : null}
                {changes.length > 0 ? (
                    <ul data-test="activity-event-changes" className="mt-1 space-y-0.5">
                        {changes.map(([field, change]) => (
                            <li key={field} className="text-xs text-muted-foreground">
                                <span className="font-mono">{field}</span>{' '}
                                {isChangePair(change)
                                    ? t('audit.beforeAfter', {
                                        before: formatChangeValue(change.before, t('audit.emptyValue')),
                                        after: formatChangeValue(change.after, t('audit.emptyValue')),
                                    })
                                    : t('audit.listChange', {
                                        values: Array.isArray(change)
                                            ? change.join(', ')
                                            : formatChangeValue(change, t('audit.emptyValue')),
                                    })}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
        </li>
    );
}
