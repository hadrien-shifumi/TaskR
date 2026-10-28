/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 */
import type { Meta, StoryObj } from '@storybook/react';

import { Progress, type ProgressColor } from '@/components/progress';

const COLORS: ProgressColor[] = ['accent', 'success', 'info', 'destructive', 'muted', 'muted-soft'];

const meta = {
    title: 'Components/Progress',
    component: Progress,
    tags: ['autodocs'],
    args: {
        value: 60,
        color: 'accent',
    },
    argTypes: {
        value: {
            control: { type: 'number', min: -20, max: 140, step: 5 },
            description: 'Percentage — clamped to 0–100 (see the Clamped story).',
        },
        color: {
            control: 'select',
            options: COLORS,
            description: 'Semantic fill slot, never a raw color value.',
        },
    },
} satisfies Meta<typeof Progress>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const Colors: Story = {
    render: () => (
        <div className="grid w-80 gap-4">
            {COLORS.map((color) => (
                <div key={color} className="grid gap-1.5">
                    <span className="font-mono text-xs text-muted-foreground">{color}</span>
                    <Progress value={60} color={color} />
                </div>
            ))}
        </div>
    ),
};

export const Values: Story = {
    render: () => (
        <div className="grid w-80 gap-4">
            {[0, 25, 60, 100].map((value) => (
                <div key={value} className="grid gap-1.5">
                    <span className="font-mono text-xs text-muted-foreground">{value}%</span>
                    <Progress value={value} />
                </div>
            ))}
        </div>
    ),
};

export const Clamped: Story = {
    render: () => (
        <div className="grid w-80 gap-4">
            <div className="grid gap-1.5">
                <span className="font-mono text-xs text-muted-foreground">value={140} → 100%</span>
                <Progress value={140} />
            </div>
            <div className="grid gap-1.5">
                <span className="font-mono text-xs text-muted-foreground">value={-15} → 0%</span>
                <Progress value={-15} />
            </div>
        </div>
    ),
};
