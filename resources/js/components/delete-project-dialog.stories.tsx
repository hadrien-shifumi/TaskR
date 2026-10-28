/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * `project` non-null = dialog open, null = closed (renders nothing). The
 * refusal banner (router.delete → onHttpException) cannot fire against the
 * no-op mock, so the gallery shows the two confirmation states.
 */
import type { Meta, StoryObj } from '@storybook/react';

import { DeleteProjectDialog } from '@/components/delete-project-dialog';
import type { ProjectRef } from '@/types';

const DESIGN_SYSTEM: ProjectRef & { health: { totalIssues: number } | null } = {
    id: '01J9X8K2M4N6Q8R0T2V4X6Z8B4',
    name: 'Design system',
    description: 'Shared components, tokens and usage docs.',
    targetDate: null,
    health: { totalIssues: 0 },
};

const WEBSITE_REDESIGN: ProjectRef & { health: { totalIssues: number } | null } = {
    id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0',
    name: 'Website redesign',
    description: 'Marketing site refresh: new pricing page, blog and SEO pass.',
    targetDate: '2026-11-30',
    health: { totalIssues: 34 },
};

const meta = {
    title: 'Components/DeleteProjectDialog',
    component: DeleteProjectDialog,
    tags: ['autodocs'],
    args: {
        project: null,
        onClose: () => {},
    },
    argTypes: {
        project: {
            control: 'object',
            description: 'Project being deleted, or null when the dialog is closed.',
        },
        onClose: { control: false },
    },
} satisfies Meta<typeof DeleteProjectDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Closed: Story = {
    name: 'Closed (renders nothing)',
    args: { project: null },
};

export const ConfirmEmptyProject: Story = {
    name: 'Empty project (irreversible warning)',
    args: { project: DESIGN_SYSTEM },
};

export const ConfirmProjectWithIssues: Story = {
    name: 'Project containing issues',
    args: { project: WEBSITE_REDESIGN },
};
