/**
 * Foundations gallery — typography. Visual counterpart of
 * docs/design-system/tokens/typography.md: every line renders the real utility
 * classes used in the app (no new arbitrary sizes), with the class string as
 * its caption. Mono samples follow the `tabular-nums` rule.
 */
import type { Meta, StoryObj } from '@storybook/react';

const FONTS = [
    {
        token: '--font-sans',
        className: 'font-sans',
        stack: "-apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif",
    },
    {
        token: '--font-heading',
        className: 'font-heading',
        stack: "-apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif",
    },
    {
        token: '--font-mono',
        className: 'font-mono',
        stack: "ui-monospace, 'JetBrains Mono', 'SF Mono', Menlo, monospace",
    },
] as const;

const SCALE = [
    {
        label: 'Pagebar title (h1)',
        classes: 'text-[17px] leading-tight font-semibold tracking-tight',
        sample: 'Issues',
    },
    {
        label: 'Section heading (h2)',
        classes: 'text-base font-semibold',
        sample: 'Health of projects',
    },
    {
        label: 'Card / block title (h3)',
        classes: 'text-sm font-semibold',
        sample: 'My issues',
    },
    {
        label: 'Body',
        classes: 'text-sm',
        sample: 'Default body text: navigation, table cells, pagebar subtitle, button labels.',
    },
    {
        label: 'Small',
        classes: 'text-xs',
        sample: 'Hints under figures, dates, discreet counters.',
    },
    {
        label: 'Meta',
        classes: 'text-[13px]',
        sample: 'Dense meta: workspace name in the switcher, comment authors, filter selects.',
    },
    {
        label: 'Micro-meta',
        classes: 'text-[11px] text-muted-foreground',
        sample: 'Role under a user name, kanban column counters, count badges.',
    },
] as const;

const meta = {
    title: 'Foundations/Typography',
    tags: ['autodocs'],
    parameters: {
        docs: {
            description: {
                component:
                    'Font stacks (`@theme` / `@theme inline` in `resources/css/app.css`) and the type scale actually used ' +
                    'by the app (see docs/design-system/tokens/typography.md). No proprietary reset: Tailwind defaults plus ' +
                    'a few usage-stabilized arbitrary sizes — reuse them, do not invent new ones.',
            },
        },
    },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

export const FontStacks: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    '`--font-sans` is applied to `<html>` by the `@layer base` — no class needed. `--font-heading` is ' +
                    'carried by the primitives (`DialogTitle`, `CardTitle`). `--font-mono` is reserved for ULIDs, numbers ' +
                    'and technical labels, always with `tabular-nums`.',
            },
        },
    },
    render: () => (
        <div className="grid max-w-3xl gap-6">
            {FONTS.map((font) => (
                <div key={font.token} className="grid gap-1">
                    <p className={`${font.className} text-base font-semibold`}>Taskr — issues & projects</p>
                    <p className="font-mono text-[11px] text-muted-foreground">{font.token}</p>
                    <p className="font-mono text-[11px] break-all text-muted-foreground">{font.stack}</p>
                </div>
            ))}
        </div>
    ),
};

export const TypeScale: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'The only sizes in use. Headings get `tracking-tight` (-0.02em); body copy never does. ' +
                    'Display titles stay in sans — `font-heading` belongs to the primitives, not the pagebar h1.',
            },
        },
    },
    render: () => (
        <div className="grid max-w-3xl gap-6">
            {SCALE.map((step) => (
                <div key={step.label} className="grid gap-1 border-b border-border pb-4">
                    <p className="font-mono text-[11px] text-muted-foreground">
                        {step.label} — {step.classes}
                    </p>
                    <p className={step.classes}>{step.sample}</p>
                </div>
            ))}
        </div>
    ),
};

export const TechnicalLabels: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'Sidebar / knowledge section-label pattern: mono, uppercase, positive letter-spacing, ' +
                    '`muted-foreground` — the only place in the UI that wants letter-spacing.',
            },
        },
    },
    render: () => (
        <div className="grid max-w-xl gap-6">
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">
                    font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground
                </p>
                <p className="font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">Workspace</p>
            </div>
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">relaxed variant — tracking-[0.04em]</p>
                <p className="font-mono text-[12px] tracking-[0.04em] uppercase text-muted-foreground">
                    Time logged this week
                </p>
            </div>
        </div>
    ),
};

export const MonoData: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    '`font-mono` is reserved for ULIDs, numbers and technical labels — never body text — and is always ' +
                    'paired with `tabular-nums` for column alignment. ULIDs additionally get `select-all` (click selects the whole id).',
            },
        },
    },
    render: () => (
        <div className="grid max-w-xl gap-6">
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">
                    ULID — font-mono text-xs text-muted-foreground tabular-nums select-all
                </p>
                <span className="w-fit font-mono text-xs text-muted-foreground tabular-nums select-all">
                    01J9X8K2M4N6Q8R0T2V4X6Z8B0
                </span>
            </div>
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">date — font-mono text-xs tabular-nums</p>
                <span className="w-fit font-mono text-xs tabular-nums">Mar 3, 2026</span>
            </div>
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">
                    stat figure — font-mono text-[28px] leading-tight font-semibold tracking-tight tabular-nums
                </p>
                <p className="font-mono text-[28px] leading-tight font-semibold tracking-tight tabular-nums">42</p>
            </div>
        </div>
    ),
};
