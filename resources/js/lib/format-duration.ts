/** Decimal hours as a clock-style duration: 0.72 → "43min", 1.5 → "1h30", 8 → "8h", 0 → "0h". */
export function formatDuration(hours: number): string {
    const totalMinutes = Math.round(hours * 60);
    const wholeHours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (wholeHours === 0 && minutes > 0) return `${minutes}min`;
    if (minutes === 0) return `${wholeHours}h`;
    return `${wholeHours}h${String(minutes).padStart(2, '0')}`;
}
