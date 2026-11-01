import { Link } from '@inertiajs/react';
import { cn } from 'cn';
import { useTranslation } from 'react-i18next';

type ProjectViewSwitcherProps = {
    projectId: string;
    /** Which view renders the switcher — the other ones are the link targets. */
    active: 'health' | 'board' | 'activity';
};

/** Pill segment switching between a project's Health, Kanban and Activity views. */
export function ProjectViewSwitcher({ projectId, active }: ProjectViewSwitcherProps) {
    const { t } = useTranslation();
    const items = [
        { key: 'health', label: t('projects.views.health'), href: route('projects.health', projectId) },
        { key: 'board', label: t('projects.views.board'), href: route('projects.board', projectId) },
        { key: 'activity', label: t('projects.views.activity'), href: route('projects.activity', projectId) },
    ] as const;

    return (
        <nav
            aria-label={t('projects.views.aria')}
            className="inline-flex items-center gap-0.5 rounded-full border bg-card p-0.5"
        >
            {items.map((item) => (
                <Link
                    key={item.key}
                    href={item.href}
                    aria-current={active === item.key ? 'page' : undefined}
                    className={cn(
                        'inline-flex h-6.5 items-center rounded-full px-3 text-xs font-medium whitespace-nowrap',
                        active === item.key
                            ? 'bg-secondary text-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
