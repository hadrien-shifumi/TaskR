/** Hours render compactly: 8 → "8h", 1.5 → "1.5h", null → "—". */
export function formatHours(hours: number | null): string {
    if (hours === null) return '—';
    return `${hours}h`;
}

export function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Days elapsed since a due date (0 when it is due today). */
export function overdueDays(dueDate: string): number {
    return Math.max(0, Math.floor((Date.now() - new Date(dueDate).getTime()) / 86_400_000));
}

/** Relative timestamp for comments: "just now", "5 min ago", "2 days ago"… */
export function timeAgo(date: string): string {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(date).getTime()) / 1000));
    if (seconds < 60) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return days === 1 ? '1 day ago' : `${days} days ago`;
    return formatDate(date);
}
