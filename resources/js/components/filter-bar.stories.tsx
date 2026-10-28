/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * FilterBar is presentational — the page owns the URL state. The harness
 * below mirrors the issues page: local `search` state, a FilterSelect chip
 * and a fake visible/total counter.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState, type ComponentProps } from 'react';

import { FilterBar } from '@/components/filter-bar';
import { FilterSelect } from '@/components/filter-select';

const STATUS_OPTIONS = [
    { value: 'backlog', label: 'Backlog' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

type FilterBarHarnessProps = {
    initialSearch?: string;
    initialStatus?: string;
    visible?: number;
    total?: number;
};

function FilterBarHarness({ initialSearch = '', initialStatus = '', visible = 8, total = 23 }: FilterBarHarnessProps) {
    const [search, setSearch] = useState(initialSearch);
    const [status, setStatus] = useState(initialStatus);

    return (
        <FilterBar
            search={search}
            onSearchChange={setSearch}
            searchPlaceholder="Search issues…"
            searchAriaLabel="Search issues by title"
            visible={visible}
            total={total}
        >
            <FilterSelect
                testId="filter-select-category"
                ariaLabel="Filter by status"
                value={status}
                options={STATUS_OPTIONS}
                allLabel="All statuses"
                onChange={setStatus}
                className="h-7.5 w-36 text-[13px]"
            />
        </FilterBar>
    );
}

type FilterBarArgs = ComponentProps<typeof FilterBar> & {
    initialSearch?: string;
    initialStatus?: string;
};

const meta = {
    title: 'Components/FilterBar',
    component: FilterBar,
    tags: ['autodocs'],
    args: {
        search: '',
        onSearchChange: () => {},
        searchPlaceholder: 'Search issues…',
        searchAriaLabel: 'Search issues by title',
        visible: 8,
        total: 23,
        initialSearch: '',
        initialStatus: '',
    },
    argTypes: {
        search: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onSearchChange: { control: false },
        searchPlaceholder: { control: 'text' },
        searchAriaLabel: { control: 'text' },
        initialSearch: { control: 'text' },
        initialStatus: {
            control: 'select',
            options: ['', 'backlog', 'in_progress', 'done', 'cancelled'],
        },
        visible: { control: 'number' },
        total: { control: 'number' },
    },
    render: (args) => (
        <FilterBarHarness
            initialSearch={args.initialSearch}
            initialStatus={args.initialStatus}
            visible={args.visible}
            total={args.total}
        />
    ),
} satisfies Meta<FilterBarArgs>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const Bare: Story = {
    name: 'Search + counter only',
    render: () => <FilterBarHarness visible={8} total={23} />,
};

export const WithActiveFilterChip: Story = {
    name: 'Active filter chip',
    render: () => <FilterBarHarness initialStatus="in_progress" visible={5} total={23} />,
};

export const NothingVisible: Story = {
    name: 'Filtered down to zero',
    render: () => <FilterBarHarness initialSearch="kubernetes" visible={0} total={23} />,
};
