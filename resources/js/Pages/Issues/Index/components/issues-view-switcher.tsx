import { router } from '@inertiajs/react';
import { cn } from 'cn';
import { useTranslation } from 'react-i18next';

type IssuesViewSwitcherProps = {
    /** Current view — the other one is the target when clicked. */
    active: 'list' | 'kanban';
    /** Current URL filters, preserved when switching views (`label` may be an array). */
    filters: Record<string, string | string[]>;
};

/** Pill segment switching between the list and kanban views of /issues. */
export function IssuesViewSwitcher({ active, filters }: IssuesViewSwitcherProps) {
    const { t } = useTranslation();

    const switchTo = (view: 'list' | 'kanban') => {
        const next: Record<string, string | string[]> = { ...filters };
        if (view === 'list') {
            delete next.view;
        } else {
            next.view = view;
        }
        router.get(route('issues.index'), next, {
            preserveState: true,
            // Only the target view's data changes: the shell (statuses,
            // projects, labels…) rides along untouched. `view` itself must
            // ride along or the client keeps rendering the old branch.
            only: view === 'kanban'
                ? ['view', 'projectBoards', 'filters']
                : ['view', 'issues', 'total', 'totalUnfiltered', 'page', 'perPage', 'filters'],
        });
    };

    const items = [
        { key: 'list' as const, label: t('issuesPage.views.list') },
        { key: 'kanban' as const, label: t('issuesPage.views.kanban') },
    ];

    return (
        <nav
            aria-label={t('issuesPage.views.aria')}
            className="inline-flex items-center gap-0.5 rounded-full border bg-card p-0.5"
            data-test="issues-view-switcher"
        >
            {items.map((item) => (
                <button
                    key={item.key}
                    type="button"
                    aria-current={active === item.key ? 'page' : undefined}
                    data-test={`issues-view-${item.key}`}
                    onClick={() => switchTo(item.key)}
                    className={cn(
                        'inline-flex h-6.5 items-center rounded-full px-3 text-xs font-medium whitespace-nowrap',
                        active === item.key
                            ? 'bg-secondary text-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                >
                    {item.label}
                </button>
            ))}
        </nav>
    );
}
