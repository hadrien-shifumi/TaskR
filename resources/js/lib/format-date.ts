import i18n from "@/i18n";

/**
 * Formats an ISO date string for the active UI language
 * (e.g. "Oct 20, 2026" / "20 oct. 2026"). Null renders the em dash.
 */
export function formatDate(date: string | null): string {
    if (!date) return "—";
    return new Date(date).toLocaleDateString(i18n.language, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}

/** Compact variant for dense surfaces (kanban cards): "Oct 20". */
export function formatShortDate(date: string | null): string {
    if (!date) return "";
    return new Date(date).toLocaleDateString(i18n.language, {
        month: "short",
        day: "numeric",
    });
}

/** Month-year variant for membership dates: "Oct 2026". */
export function formatMonthYear(date: string | null): string {
    if (!date) return "—";
    return new Date(date).toLocaleDateString(i18n.language, {
        year: "numeric",
        month: "short",
    });
}

/**
 * Relative variant for metadata rows: "just now", "3 hours ago"
 * ("il y a 3 heures") in the active UI language. Falls back to the
 * absolute date beyond ~30 days.
 */
export function formatRelativeDate(date: string | null): string {
    if (!date) return "—";
    const relative = new Intl.RelativeTimeFormat(i18n.language, {
        numeric: "auto",
    });
    const diffSeconds = Math.round((Date.now() - new Date(date).getTime()) / 1000);
    if (diffSeconds < 60) return relative.format(-diffSeconds, "second");
    const diffMinutes = Math.round(diffSeconds / 60);
    if (diffMinutes < 60) return relative.format(-diffMinutes, "minute");
    const diffHours = Math.round(diffSeconds / 3600);
    if (diffHours < 24) return relative.format(-diffHours, "hour");
    const diffDays = Math.round(diffSeconds / 86400);
    if (diffDays < 7) return relative.format(-diffDays, "day");
    if (diffDays < 30) return relative.format(-Math.round(diffDays / 7), "week");
    return formatDate(date);
}
