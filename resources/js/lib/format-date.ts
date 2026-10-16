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
