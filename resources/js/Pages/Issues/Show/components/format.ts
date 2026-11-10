import i18n from '@/i18n';
import { formatDate } from '@/lib/format-date';
import { formatDuration } from '@/lib/format-duration';

/** Hours render as a clock-style duration: 8 → "8h", 1.57 → "1h34", null → "—". */
export function formatHours(hours: number | null): string {
    if (hours === null) return '—';
    return formatDuration(hours);
}

export { formatDate };

/** Days elapsed since a due date (0 when it is due today). */
export function overdueDays(dueDate: string): number {
    return Math.max(0, Math.floor((Date.now() - new Date(dueDate).getTime()) / 86_400_000));
}

/** Relative timestamp for comments: "just now", "5 min ago", "2 days ago"… */
export function timeAgo(date: string): string {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(date).getTime()) / 1000));
    if (seconds < 60) return i18n.t('time.justNow');
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return i18n.t('time.minAgo', { count: minutes });
    const hours = Math.round(minutes / 60);
    if (hours < 24) return i18n.t('time.hoursAgo', { count: hours });
    const days = Math.round(hours / 24);
    if (days < 30) return days === 1 ? i18n.t('time.dayAgo') : i18n.t('time.daysAgo', { count: days });
    return formatDate(date);
}
