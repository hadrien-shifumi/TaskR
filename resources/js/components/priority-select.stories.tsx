/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * PrioritySelect is controlled; each demo instance keeps its own value and
 * echoes it, over the fixed PRIORITIES scale (labels via i18n).
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState, type ComponentProps } from 'react';

import { PRIORITIES, PrioritySelect } from '@/components/priority-select';

function PrioritySelectDemo({ initialValue = 'none', id }: { initialValue?: string; id?: string }) {
    const [value, setValue] = useState(initialValue);

    return (
        <div className="flex items-center gap-3">
            <PrioritySelect id={id} value={value} onValueChange={setValue} />
            <span className="font-mono text-xs text-muted-foreground">value: {value}</span>
        </div>
    );
}

type PrioritySelectArgs = ComponentProps<typeof PrioritySelect> & {
    initialValue?: string;
};

const meta = {
    title: 'Components/PrioritySelect',
    component: PrioritySelect,
    tags: ['autodocs'],
    args: {
        value: 'none',
        onValueChange: () => {},
        initialValue: 'none',
    },
    argTypes: {
        value: { control: false, description: 'Controlled by the form — the stories use a harness.' },
        onValueChange: { control: false },
        initialValue: {
            control: 'select',
            options: PRIORITIES,
            description: 'Starting priority of the Playground select.',
        },
    },
    render: (args) => <PrioritySelectDemo initialValue={args.initialValue} />,
} satisfies Meta<PrioritySelectArgs>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const AllPriorities: Story = {
    render: () => (
        <div className="grid max-w-sm gap-3">
            {PRIORITIES.map((priority, index) => (
                <PrioritySelectDemo
                    key={priority}
                    initialValue={priority}
                    id={index === 0 ? 'issue-priority' : undefined}
                />
            ))}
        </div>
    ),
};
