import { useTranslation } from 'react-i18next';
import type { ActivityEvent } from '../types';
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

/** Null or emptied values render as the localized "empty" marker. */
function formatChangeValue(value: unknown, emptyLabel: string): string {
    if (value === null || value === undefined || value === '') {
        return emptyLabel;
    }

    return String(value);
}

type ActivityEventRowProps = {
    event: ActivityEvent;
};

/**
 * One timeline line: emoji by type, i18n type label, actor name (or
 * "System"), formatted date and the field-by-field before → after changes.
 * Shared between the issue page and the board's issue modal.
 */
export function ActivityEventRow({ event }: ActivityEventRowProps) {
    const { t } = useTranslation();
    const actorName = event.actor?.name ?? t('audit.system');
    const changes = Object.entries(event.changes);

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
                        {event.createdAt ? formatDate(event.createdAt) : ''}
                    </span>
                </div>
                {changes.length > 0 ? (
                    <ul data-test="activity-event-changes" className="mt-1 space-y-0.5">
                        {changes.map(([field, change]) => (
                            <li key={field} className="text-xs text-muted-foreground">
                                <span className="font-mono">{field}</span>{' '}
                                {t('audit.beforeAfter', {
                                    before: formatChangeValue(change.before, t('audit.emptyValue')),
                                    after: formatChangeValue(change.after, t('audit.emptyValue')),
                                })}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
        </li>
    );
}
