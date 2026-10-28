/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * SettingsShell reads `workspace.role` from the shared Inertia props — each
 * story decorator seeds it through the Storybook mock so stories stay
 * order-independent.
 */
import type { Decorator, Meta, StoryObj } from '@storybook/react';
import { Plus } from 'lucide-react';

import { SettingsShell } from '@/components/settings-shell';
import { StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { setInertiaSharedProps } from '../../../.storybook/mocks/inertia-react';
import type { WorkspaceSummary } from '@/types';

const WORKSPACE_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

const withWorkspaceRole = (role: WorkspaceSummary['role']): Decorator => (Story) => {
    setInertiaSharedProps({ workspace: { id: WORKSPACE_ID, name: 'Studio Nova', role } });

    return <Story />;
};

function StatusRowsFixture() {
    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            {[
                { name: 'Backlog', category: 'backlog' },
                { name: 'In progress', category: 'in_progress' },
                { name: 'In review', category: 'in_progress' },
                { name: 'Done', category: 'done' },
            ].map((status) => (
                <div
                    key={status.name}
                    className="flex items-center justify-between border-b px-4 py-2.5 text-sm last:border-b-0"
                >
                    <span className="font-medium">{status.name}</span>
                    <StatusBadge name={status.name} category={status.category} />
                </div>
            ))}
        </div>
    );
}

const meta = {
    title: 'Components/SettingsShell',
    component: SettingsShell,
    tags: ['autodocs'],
    args: {
        title: 'Statuses',
        description: 'The columns of every project board — rename them or change their category.',
        memberReadOnly: true,
        children: null,
    },
    argTypes: {
        children: { control: false },
        actions: { control: false, description: 'Owner-only actions, hidden behind the read-only badge for members.' },
        memberReadOnly: {
            control: 'boolean',
            description: 'Whether the member read-only indicator applies (personal sections pass false).',
        },
    },
} satisfies Meta<typeof SettingsShell>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Owner: Story = {
    name: 'Owner (actions visible)',
    decorators: [withWorkspaceRole('owner')],
    args: {
        title: 'Statuses',
        description: 'The columns of every project board — rename them or change their category.',
        actions: (
            <Button size="sm">
                <Plus aria-hidden />
                New status
            </Button>
        ),
    },
    render: (args) => (
        <SettingsShell {...args}>
            <StatusRowsFixture />
        </SettingsShell>
    ),
};

export const MemberReadOnly: Story = {
    name: 'Member (read-only)',
    decorators: [withWorkspaceRole('member')],
    args: {
        title: 'Statuses',
        description: 'The columns of every project board — rename them or change their category.',
    },
    render: (args) => (
        <SettingsShell {...args}>
            <StatusRowsFixture />
        </SettingsShell>
    ),
};

export const MemberPersonalSection: Story = {
    name: 'Member personal section (tokens)',
    decorators: [withWorkspaceRole('member')],
    args: {
        title: 'API tokens',
        description: 'Personal tokens for the Taskr API — they only ever act as you.',
        memberReadOnly: false,
        actions: (
            <Button size="sm">
                <Plus aria-hidden />
                New token
            </Button>
        ),
    },
    render: (args) => (
        <SettingsShell {...args}>
            <div className="rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground">
                No token yet — create one to call the API from a script.
            </div>
        </SettingsShell>
    ),
};
