import { Button } from '@/components/ui/button';
import { addDays } from '@/Pages/MyTime/components/week-dates';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatShortDate } from '@/lib/format-date';

type WeekNavigatorProps = {
    /** Monday of the displayed week, `YYYY-MM-DD`. */
    weekStart: string;
    /** Sunday of the displayed week, `YYYY-MM-DD`. */
    weekEnd: string;
    /** Whether the displayed week is the current one (never go further). */
    atCurrent: boolean;
    /** Whether the displayed week is the 8-weeks-back floor. */
    atOldest: boolean;
    onGo: (week: string) => void;
};

/**
 * Prev / next week stepper of the pagebar. "Next" is disabled on the
 * current week and "Previous" on the 8-weeks-back floor — the server
 * clamps out-of-window weeks anyway, the UI simply never offers the
 * dead link.
 */
export function WeekNavigator({ weekStart, weekEnd, atCurrent, atOldest, onGo }: WeekNavigatorProps) {
    const { t } = useTranslation();

    return (
        <nav
            aria-label={t('myTime.gridTitle')}
            className="flex items-center gap-2"
            data-test="week-navigator"
        >
            <span className="text-sm font-medium tabular-nums">
                {t('myTime.weekOf', {
                    start: formatShortDate(weekStart),
                    end: formatShortDate(weekEnd),
                })}
            </span>
            <Button
                variant="outline"
                size="icon-sm"
                aria-label={t('myTime.previousWeek')}
                data-test="week-previous"
                onClick={() => onGo(addDays(weekStart, -7))}
                disabled={atOldest}
            >
                <ChevronLeft aria-hidden />
            </Button>
            <Button
                variant="outline"
                size="icon-sm"
                aria-label={t('myTime.nextWeek')}
                data-test="week-next"
                onClick={() => onGo(addDays(weekStart, 7))}
                disabled={atCurrent}
            >
                <ChevronRight aria-hidden />
            </Button>
        </nav>
    );
}
