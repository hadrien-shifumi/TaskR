/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * ProjectMenu composes the dropdown and both dialogs it drives. `router` is
 * a no-op mock, so archive/navigation entries are safe to click; the delete
 * entry stays disabled while the project still has issues.
 */
import type { Meta, StoryObj } from '@storybook/react';

import { ProjectMenu } from '@/components/project-menu';
import type { ProjectRef } from '@/types';

const WEBSITE_REDESIGN: ProjectRef = {
    id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0',
    name: 'Website redesign',
    description: 'Marketing site refresh: new pricing page, blog and SEO pass.',
    targetDate: '2026-11-30',
};

const meta = {
    title: 'Components/ProjectMenu',
    component: ProjectMenu,
    tags: ['autodocs'],
    args: {
        project: WEBSITE_REDESIGN,
        issuesCount: 0,
    },
    argTypes: {
        issuesCount: {
            control: 'number',
            description: 'Disables the delete entry and shows the count while above zero.',
        },
    },
} satisfies Meta<typeof ProjectMenu>;

export default meta;
type Story = StoryObj<typeof meta>;

export const EmptyProject: Story = {
    name: 'No issues (delete enabled)',
    args: { issuesCount: 0 },
};

export const WithIssues: Story = {
    name: 'Has issues (delete disabled)',
    args: { issuesCount: 12 },
};

export const LegacyProject: Story = {
    name: 'Without description or target date',
    args: {
        project: {
            id: '01J9X8K2M4N6Q8R0T2V4X6Z8B2',
            name: 'Internal tooling',
            description: null,
            targetDate: null,
        },
        issuesCount: 3,
    },
};
