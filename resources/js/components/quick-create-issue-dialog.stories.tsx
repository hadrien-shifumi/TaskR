/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * QuickCreateIssueDialog (home pagebar) only needs the workspace projects;
 * the mocked `useForm` keeps its title/project state interactive.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState } from 'react';

import { QuickCreateIssueDialog } from '@/components/quick-create-issue-dialog';
import { Button } from '@/components/ui/button';

const PROJECTS = [
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0', name: 'Website redesign' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B2', name: 'Mobile app' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B4', name: 'Design system' },
];

function QuickCreateIssueDialogHarness({ defaultOpen = false }: { defaultOpen?: boolean }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="grid gap-3">
            <Button onClick={() => setOpen(true)}>New issue</Button>
            <QuickCreateIssueDialog open={open} onClose={() => setOpen(false)} projects={PROJECTS} />
        </div>
    );
}

const meta = {
    title: 'Components/QuickCreateIssueDialog',
    component: QuickCreateIssueDialog,
    tags: ['autodocs'],
    args: {
        open: false,
        onClose: () => {},
        projects: PROJECTS,
    },
    argTypes: {
        open: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onClose: { control: false },
        projects: { control: false },
    },
} satisfies Meta<typeof QuickCreateIssueDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Interactive: Story = {
    name: 'From the trigger button',
    render: () => <QuickCreateIssueDialogHarness />,
};

export const Open: Story = {
    name: 'Open with fixtures',
    render: () => <QuickCreateIssueDialogHarness defaultOpen />,
};
