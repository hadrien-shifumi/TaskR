/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * FilterSelect maps the '' value to an internal 'all' sentinel (URL filters
 * have no empty entry). The harness echoes the current value so the mapping
 * is visible while playing with the select.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState, type ComponentProps } from 'react';

import { FilterSelect } from '@/components/filter-select';

const STATUS_OPTIONS = [
    { value: 'backlog', label: 'Backlog' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

function FilterSelectHarness({ initialValue = '' }: { initialValue?: string }) {
    const [value, setValue] = useState(initialValue);

    return (
        <div className="flex items-center gap-3">
            <FilterSelect
                testId="filter-select-category"
                ariaLabel="Filter by status"
                value={value}
                options={STATUS_OPTIONS}
                allLabel="All statuses"
                onChange={setValue}
            />
            <span className="font-mono text-xs text-muted-foreground">
                value: {value === '' ? '"" (all)' : `"${value}"`}
            </span>
        </div>
    );
}

type FilterSelectArgs = ComponentProps<typeof FilterSelect> & {
    initialValue?: string;
};

const meta = {
    title: 'Components/FilterSelect',
    component: FilterSelect,
    tags: ['autodocs'],
    args: {
        value: '',
        options: STATUS_OPTIONS,
        allLabel: 'All statuses',
        onChange: () => {},
        initialValue: '',
    },
    argTypes: {
        value: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onChange: { control: false },
        options: { control: false, description: '{ value, label }[] built by the page from its fixtures.' },
        allLabel: { control: 'text' },
        initialValue: {
            control: 'select',
            options: ['', 'backlog', 'in_progress', 'done', 'cancelled'],
            description: "Starting filter — '' is the all sentinel.",
        },
    },
    render: (args) => <FilterSelectHarness initialValue={args.initialValue} />,
} satisfies Meta<FilterSelectArgs>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const AllSelected: Story = {
    name: 'All (empty sentinel)',
    render: () => <FilterSelectHarness initialValue="" />,
};

export const OneSelected: Story = {
    name: 'One option selected',
    render: () => <FilterSelectHarness initialValue="in_progress" />,
};

export const UnknownValue: Story = {
    name: 'Unknown value falls back to allLabel',
    render: () => (
        <FilterSelect
            value="nonexistent"
            options={STATUS_OPTIONS}
            allLabel="All statuses"
            onChange={() => {}}
            ariaLabel="Filter by status"
        />
    ),
};
