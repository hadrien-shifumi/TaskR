import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { PageSharedProps } from '@/types';

const SECTIONS = [
    { path: '/settings/statuses', name: 'settings.statuses', label: 'Statuses' },
    { path: '/settings/members', name: 'settings.members', label: 'Members' },
    { path: '/settings/templates', name: 'settings.templates', label: 'Templates' },
    { path: '/settings/tokens', name: 'settings.tokens', label: 'API tokens' },
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
    const isOwner = workspace?.role === 'owner';
    const readOnly = memberReadOnly && !isOwner;

    return (
        <div className="space-y-5">
            <nav
                aria-label="Settings sections"
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
                        {section.label}
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
                        Read-only — member
                    </span>
                )}
            </div>

            {readOnly ? (
                <p className="flex items-start gap-2.5 rounded-lg border border-info/30 bg-info/10 px-3.5 py-3 text-sm">
                    <Lock className="mt-0.5 size-4 shrink-0 text-info" />
                    <span>
                        Members see settings read-only. Managing statuses, members and templates is
                        reserved for workspace <strong>owners</strong>.
                    </span>
                </p>
            ) : null}

            {children}
        </div>
    );
}
