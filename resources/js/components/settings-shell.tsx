import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { PageSharedProps } from '@/types';

const SECTIONS = [
    { name: 'settings.statuses', label: 'Statuses' },
    { name: 'settings.members', label: 'Members' },
    { name: 'settings.templates', label: 'Templates' },
];

interface SettingsShellProps {
    title: string;
    description: string;
    /** Owner-only actions rendered next to the section title. */
    actions?: ReactNode;
    children: ReactNode;
}

/**
 * Shared settings chrome (ADR-4): tab navigation across the settings
 * sections — each tab stays its own route and responder — plus the
 * read-only indicator members see instead of owner-only controls.
 */
export function SettingsShell({ title, description, actions, children }: SettingsShellProps) {
    const { workspace } = usePage<PageSharedProps>().props;
    const isOwner = workspace?.role === 'owner';
    const current = route().current();

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
                        aria-current={current === section.name ? 'page' : undefined}
                        className={`-mb-px border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors ${
                            current === section.name
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {section.label}
                    </Link>
                ))}
                <span
                    aria-disabled="true"
                    title="Delivered with API access for agents — coming soon"
                    className="-mb-px flex cursor-not-allowed items-center gap-2 border-b-2 border-transparent px-3.5 py-2.5 text-sm font-medium text-muted-foreground/60"
                >
                    API tokens
                    <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[10px] tracking-wide uppercase">
                        soon
                    </span>
                </span>
            </nav>

            <div className="flex flex-wrap items-center gap-3.5">
                <div>
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
                </div>
                <div className="flex-1" />
                {isOwner ? (
                    actions
                ) : (
                    <span className="inline-flex items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-xs text-muted-foreground">
                        <Lock className="size-3.5" />
                        Read-only — member
                    </span>
                )}
            </div>

            {isOwner ? null : (
                <p className="flex items-start gap-2.5 rounded-lg border border-info/30 bg-info/10 px-3.5 py-3 text-sm">
                    <Lock className="mt-0.5 size-4 shrink-0 text-info" />
                    <span>
                        Members see settings read-only. Managing statuses, members and templates is
                        reserved for workspace <strong>owners</strong>.
                    </span>
                </p>
            )}

            {children}
        </div>
    );
}
