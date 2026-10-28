/**
 * Foundations gallery — radii, elevation, hairlines. Visual counterpart of
 * docs/design-system/tokens/radii-and-elevation.md. The radius scale is flat
 * on the two maquette values (8px and 12px master); shadows are reserved for
 * dialogs and menus; every static separation is a border-border hairline.
 */
import type { Meta, StoryObj } from '@storybook/react';

const RADII = [
    { name: 'rounded-sm', rem: '0.5rem', px: '8px', usage: 'Very small parts' },
    { name: 'rounded-md', rem: '0.5rem', px: '8px', usage: 'Menu / select items, inner blocks' },
    { name: 'rounded-lg', rem: '0.75rem', px: '12px', usage: 'Master radius of controls: Button, Input, Select' },
    { name: 'rounded-xl', rem: '0.75rem', px: '12px', usage: 'Containers: Card, DialogContent, kanban blocks' },
    { name: 'rounded-2xl', rem: '1rem', px: '16px', usage: 'Reserved' },
    { name: 'rounded-3xl', rem: '1.25rem', px: '20px', usage: 'Reserved' },
    { name: 'rounded-4xl', rem: '1.5rem', px: '24px', usage: 'Reserved' },
] as const;

const ELEVATIONS = [
    { label: 'Grounded control', classes: 'rounded-lg shadow-xs', note: 'shadow-xs — switcher, segmented control, outline trigger' },
    { label: 'Menu', classes: 'rounded-lg shadow-md', note: 'shadow-md — dropdown, select' },
    { label: 'Dialog', classes: 'rounded-xl shadow-lg', note: 'shadow-lg — dialog (the other true elevation)' },
    { label: 'Static card', classes: 'rounded-xl', note: 'no shadow — hairline + bg-card / bg-background contrast' },
] as const;

const meta = {
    title: 'Foundations/Radii',
    tags: ['autodocs'],
    parameters: {
        docs: {
            description: {
                component:
                    'Radius, elevation and hairline tokens from `resources/css/app.css` (`@theme inline`), per ' +
                    'docs/design-system/tokens/radii-and-elevation.md. Maquette rule: 12px master radius, 8px for small ' +
                    'elements; `sm = md` and `lg = xl` on purpose — 2xl–4xl exist for Tailwind compatibility but stay out of the UI.',
            },
        },
    },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

export const Radii: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'Compact sizes clamp the radius relative to the token, never in raw pixels: ' +
                    '`rounded-[min(var(--radius-md),10px)]` (xs) and `rounded-[min(var(--radius-md),12px)]` (sm) — ' +
                    'reuse the `min()` pattern as-is for any new compact size.',
            },
        },
    },
    render: () => (
        <div className="flex flex-wrap items-end gap-6">
            {RADII.map((radius) => (
                <div key={radius.name} className="grid w-32 gap-1.5">
                    <div className={`h-24 border border-border bg-secondary ${radius.name}`} />
                    <p className="text-sm font-medium">{radius.name}</p>
                    <p className="font-mono text-[11px] text-muted-foreground">
                        {radius.rem} · {radius.px}
                    </p>
                    <p className="text-[11px] text-muted-foreground">{radius.usage}</p>
                </div>
            ))}
        </div>
    ),
};

export const Elevation: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'Maquette rule: shadows are reserved for dialogs and menus. Grounded controls cap at `shadow-xs` ' +
                    '(micro-relief, not elevation); static cards get none — separation comes from the hairline and the ' +
                    '`bg-card` / `bg-background` contrast. Any `shadow-*` above `shadow-xs` outside menus/dialogs is a deviation.',
            },
        },
    },
    render: () => (
        <div className="flex flex-wrap items-start gap-8">
            {ELEVATIONS.map((sample) => (
                <div key={sample.label} className="grid w-44 gap-2">
                    <div
                        className={`grid h-16 place-items-center border border-border bg-popover text-sm ${sample.classes}`}
                    >
                        {sample.label}
                    </div>
                    <p className="text-sm font-medium">{sample.label}</p>
                    <p className="font-mono text-[11px] break-words text-muted-foreground">{sample.note}</p>
                </div>
            ))}
        </div>
    ),
};

export const Hairlines: Story = {
    parameters: {
        docs: {
            description: {
                story:
                    'The `@layer base` applies `border-border` to `*`: any unqualified 1px border is already a tokenized ' +
                    'hairline — writing `border` / `border-b` / `border-r` is enough. No 2px borders, no double borders: ' +
                    'adjacent blocks share a single hairline. Colored borders are the semantic exception (focus, `aria-invalid`, banners).',
            },
        },
    },
    render: () => (
        <div className="grid max-w-xl gap-8">
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">border — full hairline (color from the global @layer base)</p>
                <div className="rounded-lg border bg-card p-4 text-sm">
                    A card separated from the page by a single 1px border-border hairline — no shadow.
                </div>
            </div>
            <div className="grid gap-2">
                <p className="font-mono text-[11px] text-muted-foreground">border-b — stacked sections, one hairline each</p>
                <div className="rounded-lg border bg-card">
                    <p className="border-b p-3 text-sm">First section</p>
                    <p className="border-b p-3 text-sm">Second section</p>
                    <p className="p-3 text-sm">Third section</p>
                </div>
            </div>
        </div>
    ),
};
