/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * CreateIssueDialog is mounted by the issues page with its list fixtures;
 * the harness reproduces that shape. `useForm` is mocked with working local
 * state, so every field — template pre-fill included — stays interactive.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState } from 'react';

import { CreateIssueDialog } from '@/components/create-issue-dialog';
import { Button } from '@/components/ui/button';
import type { LabelRef } from '@/types';
import type { TemplateOption } from '@/Pages/Issues/Index/types';

const PROJECTS = [
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B0', name: 'Website redesign' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B2', name: 'Mobile app' },
    { id: '01J9X8K2M4N6Q8R0T2V4X6Z8B4', name: 'Design system' },
];

const STATUSES = [
    { id: '01JBY7Q2M4N6Q8R0T2V4X6Z8C1', name: 'Backlog', category: 'backlog' },
    { id: '01JBY7Q2M4N6Q8R0T2V4X6Z8C2', name: 'In progress', category: 'in_progress' },
    { id: '01JBY7Q2M4N6Q8R0T2V4X6Z8C3', name: 'In review', category: 'in_progress' },
    { id: '01JBY7Q2M4N6Q8R0T2V4X6Z8C4', name: 'Done', category: 'done' },
    { id: '01JBY7Q2M4N6Q8R0T2V4X6Z8C5', name: 'Cancelled', category: 'cancelled' },
];

const LABELS: LabelRef[] = [
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A31', name: 'Bug', color: 'red' },
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A33', name: 'Design', color: 'violet' },
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A35', name: 'Documentation', color: 'sky' },
];

const TEMPLATES: TemplateOption[] = [
    {
        id: '01JD2F7M9N3Q5S7U9W1Y3A5B71',
        name: 'Bug report',
        description: '## Steps to reproduce\n\n1. Open the page\n2. Do the thing\n\n**Expected:** it works.',
        priority: 'high',
        labels: [{ id: '01JC0D5K8M2P4R6T8V0X2Z4A31', name: 'Bug' }],
    },
    {
        id: '01JD2F7M9N3Q5S7U9W1Y3A5B73',
        name: 'Feature request',
        description: null,
        priority: 'low',
        labels: [],
    },
];

type CreateIssueDialogHarnessProps = {
    defaultOpen?: boolean;
    withTemplatesAndLabels?: boolean;
};

function CreateIssueDialogHarness({ defaultOpen = false, withTemplatesAndLabels = true }: CreateIssueDialogHarnessProps) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="grid gap-3">
            <Button onClick={() => setOpen(true)}>New issue</Button>
            <CreateIssueDialog
                open={open}
                onClose={() => setOpen(false)}
                projects={PROJECTS}
                statuses={STATUSES}
                labels={withTemplatesAndLabels ? LABELS : []}
                templates={withTemplatesAndLabels ? TEMPLATES : []}
            />
        </div>
    );
}

const meta = {
    title: 'Components/CreateIssueDialog',
    component: CreateIssueDialog,
    tags: ['autodocs'],
    args: {
        open: false,
        onClose: () => {},
        projects: PROJECTS,
        statuses: STATUSES,
        labels: LABELS,
        templates: TEMPLATES,
    },
    argTypes: {
        open: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onClose: { control: false },
        projects: { control: false },
        statuses: { control: false },
        labels: { control: false },
        templates: { control: false },
    },
} satisfies Meta<typeof CreateIssueDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Interactive: Story = {
    name: 'From the trigger button',
    render: () => <CreateIssueDialogHarness />,
};

export const Open: Story = {
    name: 'Open with fixtures',
    render: () => <CreateIssueDialogHarness defaultOpen />,
};

export const BareWorkspace: Story = {
    name: 'No templates, no labels',
    render: () => <CreateIssueDialogHarness defaultOpen withTemplatesAndLabels={false} />,
};
