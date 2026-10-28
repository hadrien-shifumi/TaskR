/**
 * Foundations gallery — color tokens. Visual counterpart of
 * docs/design-system/tokens/colors.md: every sample block is styled with the
 * token utility class (never a raw color); the oklch / color-mix strings shown
 * as text are the verbatim LIGHT-theme (`:root`) values from
 * resources/css/app.css.
 *
 * The swatches are theme-agnostic by construction: flipping the Storybook
 * Theme toolbar switch (`.dark` on <html>) re-resolves every token live.
 */
import type { Meta, StoryObj } from '@storybook/react';

type Swatch = {
    /** Token name as declared in app.css (without the `--` prefix). */
    name: string;
    /** Token utility class rendering the sample block. */
    bgClass: string;
    /** Verbatim light-theme value: oklch, or the color-mix formula for derived slots. */
    value: string;
};

type SwatchGroup = {
    title: string;
    swatches: Swatch[];
};

const GROUPS: SwatchGroup[] = [
    {
        title: 'Surfaces',
        swatches: [
            { name: 'background', bgClass: 'bg-background', value: 'oklch(0.99 0.002 240)' },
            { name: 'card', bgClass: 'bg-card', value: 'oklch(1 0 0)' },
            { name: 'popover', bgClass: 'bg-popover', value: 'oklch(1 0 0)' },
            { name: 'sidebar', bgClass: 'bg-sidebar', value: 'oklch(0.985 0.003 240)' },
        ],
    },
    {
        title: 'Text',
        swatches: [
            { name: 'foreground', bgClass: 'bg-foreground', value: 'oklch(0.18 0.012 250)' },
            { name: 'card-foreground', bgClass: 'bg-card-foreground', value: 'oklch(0.18 0.012 250)' },
            { name: 'popover-foreground', bgClass: 'bg-popover-foreground', value: 'oklch(0.18 0.012 250)' },
            { name: 'primary-foreground', bgClass: 'bg-primary-foreground', value: 'oklch(0.99 0.002 240)' },
            { name: 'secondary-foreground', bgClass: 'bg-secondary-foreground', value: 'oklch(0.18 0.012 250)' },
            { name: 'accent-foreground', bgClass: 'bg-accent-foreground', value: 'oklch(0.18 0.012 250)' },
            { name: 'muted-foreground', bgClass: 'bg-muted-foreground', value: 'oklch(0.54 0.012 250)' },
        ],
    },
    {
        title: 'Interactive',
        swatches: [
            { name: 'primary', bgClass: 'bg-primary', value: 'oklch(0.55 0.18 255)' },
            { name: 'secondary', bgClass: 'bg-secondary', value: 'color-mix(in oklch, var(--foreground) 4%, transparent)' },
            { name: 'accent', bgClass: 'bg-accent', value: 'color-mix(in oklch, var(--foreground) 3%, var(--card))' },
            { name: 'muted', bgClass: 'bg-muted', value: 'color-mix(in oklch, var(--foreground) 4%, transparent)' },
        ],
    },
    {
        title: 'Semantic',
        swatches: [
            { name: 'destructive', bgClass: 'bg-destructive', value: 'oklch(0.55 0.19 25)' },
            { name: 'warning', bgClass: 'bg-warning', value: 'oklch(0.62 0.14 70)' },
            { name: 'success', bgClass: 'bg-success', value: 'oklch(0.58 0.13 150)' },
            { name: 'info', bgClass: 'bg-info', value: 'oklch(0.58 0.13 250)' },
        ],
    },
    {
        title: 'Lines & focus',
        swatches: [
            { name: 'border', bgClass: 'bg-border', value: 'oklch(0.92 0.005 250)' },
            { name: 'input', bgClass: 'bg-input', value: 'oklch(0.92 0.005 250)' },
            { name: 'ring', bgClass: 'bg-ring', value: 'color-mix(in oklch, var(--primary) 35%, transparent)' },
        ],
    },
    {
        title: 'Charts',
        swatches: [
            { name: 'chart-1', bgClass: 'bg-chart-1', value: 'oklch(0.58 0.13 250)' },
            { name: 'chart-2', bgClass: 'bg-chart-2', value: 'oklch(0.58 0.13 150)' },
            { name: 'chart-3', bgClass: 'bg-chart-3', value: 'oklch(0.62 0.14 70)' },
            { name: 'chart-4', bgClass: 'bg-chart-4', value: 'oklch(0.55 0.19 25)' },
            { name: 'chart-5', bgClass: 'bg-chart-5', value: 'oklch(0.54 0.012 250)' },
        ],
    },
];

const SOFT_PILLS = [
    { label: 'Info', classes: 'bg-info/10 text-info' },
    { label: 'Success', classes: 'bg-success/10 text-success' },
    { label: 'Warning', classes: 'bg-warning/10 text-warning' },
    { label: 'Destructive', classes: 'bg-destructive/10 text-destructive' },
] as const;

const meta = {
    title: 'Foundations/Colors',
    tags: ['autodocs'],
    parameters: {
        docs: {
            description: {
                component:
                    'Semantic color slots from `resources/css/app.css` (ADR-1 mapping, see docs/design-system/tokens/colors.md). ' +
                    'The values printed under each swatch are the **light theme** (`:root`) verbatim values; flip the Theme ' +
                    'toolbar switch to Dark to watch the same utility classes re-resolve — swatches never hardcode a color.',
            },
        },
    },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

function SwatchCard({ name, bgClass, value }: Swatch) {
    return (
        <div className="grid gap-2">
            <div className={`h-16 rounded-lg border border-border ${bgClass}`} />
            <div className="min-w-0">
                <p className="text-sm font-medium">{name}</p>
                <p className="font-mono text-[11px] break-all text-muted-foreground">{value}</p>
            </div>
        </div>
    );
}

export const Swatches: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'Light-theme values, grouped by role. Derived slots (`secondary`, `muted`, `accent`, `ring`) display ' +
                    'their `color-mix(in oklch, …)` formula — they follow `--foreground` / `--primary` automatically, in both themes.',
            },
        },
    },
    render: () => (
        <div className="grid gap-10">
            <p className="text-xs text-muted-foreground">
                Values shown are the light theme (:root) — use the Theme toolbar switch to preview the dark tokens on
                these same swatches, live.
            </p>
            {GROUPS.map((group) => (
                <section key={group.title} className="grid gap-4">
                    <h3 className="font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                        {group.title}
                    </h3>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                        {group.swatches.map((swatch) => (
                            <SwatchCard key={swatch.name} {...swatch} />
                        ))}
                    </div>
                </section>
            ))}
        </div>
    ),
};

export const SoftVariants: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'The badge / banner soft pattern from `issue-badges.tsx`: a 10%-opacity token background plus the ' +
                    'same token at full strength for the text and the dot — the only approved way to tint a surface. ' +
                    'No `dark:` overrides: flip the Theme toolbar switch and both layers re-derive live.',
            },
        },
    },
    render: () => (
        <div className="grid max-w-2xl gap-4">
            <div className="flex flex-wrap items-center gap-3">
                {SOFT_PILLS.map((pill) => (
                    <span
                        key={pill.label}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${pill.classes}`}
                    >
                        <span className="size-1.5 shrink-0 rounded-full bg-current" />
                        {pill.label}
                    </span>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                Same classes as StatusBadge / PriorityBadge / HealthBadge (bg-*/10 text-*, rounded-full). The color lives
                in the semantic category, never in the free name. Switch the toolbar theme to Light/Dark — the pills
                flip with it.
            </p>
        </div>
    ),
};
