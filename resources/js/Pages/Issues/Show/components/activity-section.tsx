import { useTranslation } from 'react-i18next';
import type { ActivityEvent } from '../types';
import { ActivityEventRow } from './activity-event-row';
import { SectionAccordion } from './section-accordion';
import { SectionHead } from './section-head';

type ActivitySectionProps = {
    events: ActivityEvent[];
    /**
     * Controlled accordion state. When omitted the section renders fully
     * expanded — the project activity page shares this component and has no
     * fold; the issue fiche passes the state and folds it by default.
     */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /**
     * Jump handler for live comment events: opens the comments accordion if
     * needed, then scrolls and flashes the target row. Returns false when
     * the comment no longer exists so the row falls back to its snapshot.
     */
    onJumpToComment?: (commentId: string) => boolean;
};

/**
 * Audit timeline of the issue: one row per journalled event, newest first
 * (order chosen by the backend query). Shared between the issue page and
 * the board's issue modal (folded accordion) and the project activity page
 * (always expanded).
 */
export function ActivitySection({ events, open, onOpenChange, onJumpToComment }: ActivitySectionProps) {
    const { t } = useTranslation();

    if (open === undefined || onOpenChange === undefined) {
        return (
            <section data-test="activity-section">
                <SectionHead title={t('audit.title')} count={events.length} />
                {events.length === 0 ? (
                    <p data-test="activity-empty" className="text-sm text-muted-foreground">{t('audit.empty')}</p>
                ) : (
                    <ul className="divide-y">
                        {events.map((event) => (
                            <ActivityEventRow key={event.id} event={event} />
                        ))}
                    </ul>
                )}
            </section>
        );
    }

    return (
        <section data-test="activity-section">
            <SectionAccordion
                title={t('audit.title')}
                count={events.length}
                open={open}
                onOpenChange={onOpenChange}
                triggerTestId="activity-accordion-trigger"
                panelTestId="activity-panel"
                ariaLabel={t('audit.accordionLabel')}
            >
                {events.length === 0 ? (
                    <p data-test="activity-empty" className="text-sm text-muted-foreground">{t('audit.empty')}</p>
                ) : (
                    <ul className="divide-y">
                        {events.map((event) => (
                            <ActivityEventRow
                                key={event.id}
                                event={event}
                                onJumpToComment={onJumpToComment}
                            />
                        ))}
                    </ul>
                )}
            </SectionAccordion>
        </section>
    );
}
