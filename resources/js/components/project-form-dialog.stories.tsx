/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * `project` selects the mode (null = create); the component seeds its
 * `useForm` state from the row on open, so the edit story shows real values.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState } from 'react';

import { ProjectFormDialog } from '@/components/project-form-dialog';
import { Button } from '@/components/ui/button';
import type { ProjectRef } from '@/types';

const WEBSITE_REDESIGN: ProjectRef = {
    id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0',
    name: 'Website redesign',
    description: 'Marketing site refresh: new pricing page, blog and SEO pass.',
    targetDate: '2026-11-30',
};

function ProjectFormDialogHarness({ project = null }: { project?: ProjectRef | null }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="grid gap-3">
            <Button onClick={() => setOpen(true)}>{project ? `Edit « ${project.name} »` : 'New project'}</Button>
            <ProjectFormDialog open={open} project={project} onClose={() => setOpen(false)} />
        </div>
    );
}

const meta = {
    title: 'Components/ProjectFormDialog',
    component: ProjectFormDialog,
    tags: ['autodocs'],
    args: {
        open: false,
        project: null,
        onClose: () => {},
    },
    argTypes: {
        open: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onClose: { control: false },
        project: { control: false, description: 'Project being edited, or null in create mode.' },
    },
} satisfies Meta<typeof ProjectFormDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const CreateMode: Story = {
    name: 'Create (from the trigger button)',
    render: () => <ProjectFormDialogHarness />,
};

export const EditMode: Story = {
    name: 'Edit (row values seeded)',
    render: () => <ProjectFormDialogHarness project={WEBSITE_REDESIGN} />,
};
