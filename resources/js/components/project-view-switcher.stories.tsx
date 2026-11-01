/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * ProjectViewSwitcher only needs a project id — the ziggy stub turns
 * `route()` into `#name` anchors (Link is mocked to a plain <a>).
 */
import type { Meta, StoryObj } from '@storybook/react';

import { ProjectViewSwitcher } from '@/components/project-view-switcher';

const PROJECT_ID = '01J9X8K2M4N6Q8R0T2V4X6Z8B0';

const meta = {
    title: 'Components/ProjectViewSwitcher',
    component: ProjectViewSwitcher,
    tags: ['autodocs'],
    args: {
        projectId: PROJECT_ID,
        active: 'health',
    },
    argTypes: {
        active: {
            control: 'select',
            options: ['health', 'board', 'activity'],
            description: 'Which view renders the switcher — the other ones are the link targets.',
        },
    },
} satisfies Meta<typeof ProjectViewSwitcher>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const HealthActive: Story = {
    args: { active: 'health' },
};

export const BoardActive: Story = {
    args: { active: 'board' },
};

export const ActivityActive: Story = {
    args: { active: 'activity' },
};

export const InPagebar: Story = {
    name: 'Next to a pagebar action',
    render: () => (
        <div className="flex items-center justify-between rounded-xl border bg-card px-4 py-2.5">
            <h1 className="text-base font-semibold">Website redesign</h1>
            <ProjectViewSwitcher projectId={PROJECT_ID} active="health" />
        </div>
    ),
};
