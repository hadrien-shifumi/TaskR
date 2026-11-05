import { Banner } from '@/components/banner';
import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';

const SECTIONS = [
    { path: '/settings/account', name: 'settings.account', label: 'settings.sections.account' },
    { path: '/settings/general', name: 'settings.general', label: 'settings.sections.general' },
    { path: '/settings/statuses', name: 'settings.statuses', label: 'settings.sections.statuses' },
    { path: '/settings/members', name: 'settings.members', label: 'settings.sections.members' },
    { path: '/settings/templates', name: 'settings.templates', label: 'settings.sections.templates' },
    { path: '/settings/tokens', name: 'settings.tokens', label: 'settings.sections.tokens' },
    { path: '/settings/notifications', name: 'settings.notifications', label: 'settings.sections.notifications' },
];

interface SettingsShellProps {
    title: string;
    description: string;
    /** Owner-only actions rendered next to the section title. */
    actions?: ReactNode;
    /**
     * Whether the member read-only indicator applies. Workspace settings are
     * owner-managed; personal sections (API tokens) are every user's own.
     */
    memberReadOnly?: boolean;
    children: ReactNode;
}

/**
 * Shared settings chrome (ADR-4): tab navigation across the settings
 * sections — each tab stays its own route and responder — plus the
 * read-only indicator members see instead of owner-only controls.
 */
export function SettingsShell({ title, description, actions, memberReadOnly = true, children }: SettingsShellProps) {
    const { props, url } = usePage<PageSharedProps>();
    const { workspace } = props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';
    const readOnly = memberReadOnly && !isOwner;

    return (
        <div className="space-y-5">
            <nav
                aria-label={t('settings.sectionsAria')}
                className="flex gap-0.5 border-b"
            >
                {SECTIONS.map((section) => (
                    <Link
                        key={section.name}
                        href={route(section.name)}
                        aria-current={url.startsWith(section.path) ? 'page' : undefined}
                        className={`-mb-px border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors ${
                            url.startsWith(section.path)
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {t(section.label)}
                    </Link>
                ))}
            </nav>

            <div className="flex flex-wrap items-center gap-3.5">
                <div>
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
                </div>
                <div className="flex-1" />
                {!readOnly ? (
                    actions
                ) : (
                    <span className="inline-flex items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-xs text-muted-foreground">
                        <Lock className="size-3.5" />
                        {t('settings.readOnlyBadge')}
                    </span>
                )}
            </div>

            {readOnly ? (
                <Banner variant="info" icon={Lock}>
                    {t('settings.readOnlyPrefix')} <strong>{t('settings.ownerWord')}</strong>
                    {t('settings.readOnlySuffix')}
                </Banner>
            ) : null}

            {children}
        </div>
    );
}
