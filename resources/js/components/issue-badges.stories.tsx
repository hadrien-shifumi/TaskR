/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * `issue-badges.tsx` exports the whole badge + avatar family; StatusBadge is
 * the meta component (its props drive the Playground controls) and the other
 * exports get their own gallery stories.
 */
import type { Meta, StoryObj } from '@storybook/react';

import { Avatar, AvatarStack, HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';

const meta = {
    title: 'Components/IssueBadges',
    component: StatusBadge,
    tags: ['autodocs'],
    args: {
        name: 'In progress',
        category: 'in_progress',
    },
    argTypes: {
        name: { control: 'text', description: 'Free display name of the status.' },
        category: {
            control: 'select',
            options: ['backlog', 'in_progress', 'done', 'cancelled'],
            description: 'Drives the tone — the name itself is never colored.',
        },
    },
} satisfies Meta<typeof StatusBadge>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const Statuses: Story = {
    render: () => (
        <div className="flex flex-wrap items-center gap-2">
            <StatusBadge name="Backlog" category="backlog" />
            <StatusBadge name="In progress" category="in_progress" />
            <StatusBadge name="Done" category="done" />
            <StatusBadge name="Cancelled" category="cancelled" />
            <StatusBadge name="QA review" category="backlog" />
        </div>
    ),
};

export const Priorities: Story = {
    render: () => (
        <div className="flex flex-wrap items-center gap-2">
            <PriorityBadge priority="urgent" />
            <PriorityBadge priority="high" />
            <PriorityBadge priority="low" />
            <PriorityBadge priority="none" />
        </div>
    ),
};

export const Health: Story = {
    render: () => (
        <div className="flex flex-wrap items-center gap-2">
            <HealthBadge status="ok" />
            <HealthBadge status="at-risk" />
            <HealthBadge status="late" />
            <HealthBadge status={null} />
        </div>
    ),
};

const PEOPLE = [
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0', name: 'Ada Lovelace' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B2', name: 'Grace Hopper' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B4', name: 'Alan Turing' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B6', name: 'Katherine Johnson' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B8', name: 'Margaret Hamilton' },
];

export const Avatars: Story = {
    name: 'Avatar',
    render: () => (
        <div className="grid max-w-md gap-3 text-sm">
            <div className="flex items-center gap-3">
                <Avatar name="Ada Lovelace" />
                <span className="font-mono text-xs text-muted-foreground">first + last initials</span>
            </div>
            <div className="flex items-center gap-3">
                <Avatar name="Guido" />
                <span className="font-mono text-xs text-muted-foreground">single word → first two letters</span>
            </div>
            <div className="flex items-center gap-3">
                <Avatar name="X" />
                <span className="font-mono text-xs text-muted-foreground">single letter</span>
            </div>
            <div className="flex items-center gap-3">
                <Avatar name="Bartholomew Montgomery Fitzwilliam III" />
                <span className="font-mono text-xs text-muted-foreground">long name, first + last</span>
            </div>
        </div>
    ),
};

export const AvatarStacks: Story = {
    name: 'AvatarStack',
    render: () => (
        <div className="grid max-w-md gap-4 text-sm">
            <div className="flex items-center gap-3">
                <AvatarStack people={PEOPLE.slice(0, 1)} />
                <span className="font-mono text-xs text-muted-foreground">1 person</span>
            </div>
            <div className="flex items-center gap-3">
                <AvatarStack people={PEOPLE.slice(0, 3)} />
                <span className="font-mono text-xs text-muted-foreground">3 people</span>
            </div>
            <div className="flex items-center gap-3">
                <AvatarStack people={PEOPLE} />
                <span className="font-mono text-xs text-muted-foreground">5 people</span>
            </div>
            <div className="flex items-center gap-3">
                <AvatarStack people={[]} />
                <span className="font-mono text-xs text-muted-foreground">empty — renders nothing (nullable cell)</span>
            </div>
        </div>
    ),
};
