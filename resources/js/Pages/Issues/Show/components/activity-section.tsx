import { useTranslation } from 'react-i18next';
import type { ActivityEvent } from '../types';
import { ActivityEventRow } from './activity-event-row';
import { SectionHead } from './section-head';

type ActivitySectionProps = {
    events: ActivityEvent[];
};

/**
 * Audit timeline of the issue: one row per journalled event, newest first
 * (order chosen by the backend query). Shared between the issue page and
 * the board's issue modal.
 */
export function ActivitySection({ events }: ActivitySectionProps) {
    const { t } = useTranslation();

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
