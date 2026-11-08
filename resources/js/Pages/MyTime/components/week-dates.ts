/**
 * Week arithmetic for the timesheet, all on plain `YYYY-MM-DD` strings in
 * UTC — no local-time drift between the server's week bounds and the grid.
 */

/** Adds days to a `YYYY-MM-DD` date, returning a `YYYY-MM-DD` date. */
export function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/** The seven `YYYY-MM-DD` days of a week starting on its Monday. */
export function weekDays(weekStart: string): string[] {
    return Array.from({ length: 7 }, (_, index) => addDays(weekStart, index));
}

/** Monday of today (local clock), as `YYYY-MM-DD`. */
export function currentMonday(): string {
    const now = new Date();
    const utc = new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
    const day = utc.getUTCDay();
    const diff = (day === 0 ? -6 : 1) - day;
    utc.setUTCDate(utc.getUTCDate() + diff);
    return utc.toISOString().slice(0, 10);
}
