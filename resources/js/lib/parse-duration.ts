/**
 * Parses a user-typed duration into decimal hours. Accepted formats:
 * - plain number: "1.5" or "1,5" → hours (keeps the timer proposal working)
 * - unit suffixes: "1.5h", "90m", "1h30m", "45s" (h/m/s, French aliases ok)
 * - clock format: "1:30" → 1h30, "0:45:15" → h:m:s (minutes/seconds must be < 60)
 * Returns null when the input matches none of these.
 */
export function parseDuration(input: string): number | null {
    const text = input.trim().toLowerCase().replace(/\s+/g, '');
    if (text === '') return null;

    if (/^\d+(?:[.,]\d+)?$/.test(text)) return Number(text.replace(',', '.'));

    const clock = /^(\d+):(\d{1,2})(?::(\d{1,2}))?$/.exec(text);
    if (clock) {
        const minutes = Number(clock[2]);
        const seconds = Number(clock[3] ?? '0');
        if (minutes >= 60 || seconds >= 60) return null;
        return Number(clock[1]) + minutes / 60 + seconds / 3600;
    }

    const group = /(\d+(?:[.,]\d+)?)(heures?|hrs?|h|minutes?|mins?|m|secondes?|secs?|s)/y;
    let total = 0;
    let pos = 0;
    while (pos < text.length) {
        group.lastIndex = pos;
        const match = group.exec(text);
        if (!match) return null;
        const value = Number(match[1].replace(',', '.'));
        const unit = match[2];
        if (unit.startsWith('h')) total += value;
        else if (unit.startsWith('m')) total += value / 60;
        else total += value / 3600;
        pos = group.lastIndex;
    }

    return total;
}
