/**
 * Story conventions for the Taskr design-system gallery (run `npm run
 * storybook`):
 *
 * - Stories are colocated with their component as `*.stories.tsx` next to
 *   the component file under `resources/js/components/`.
 * - Use CSF3: `satisfies Meta<typeof Component>` plus `StoryObj<typeof meta>`.
 * - Titles: `Components/<Name>` for app components (matching the references
 *   in docs/design-system/components.md).
 * - Never use raw hex colors — style with Tailwind design tokens only.
 * - Indentation: 4 spaces under `components/`, single quotes, semicolons.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { Lock } from 'lucide-react';

import { Banner } from '@/components/banner';

const meta = {
    title: 'Components/Banner',
    component: Banner,
    tags: ['autodocs'],
    args: {
        variant: 'info',
        role: 'status',
        children: 'Members can browse the workspace but only owners can change its settings.',
    },
    argTypes: {
        variant: {
            control: 'select',
            options: ['info', 'warning', 'danger'],
            description: 'Semantic tone — border, soft background and default icon.',
        },
        icon: {
            control: false,
            description:
                'LucideIcon component or null. Defaults to the variant icon — see the CustomIcon and NoIcon stories.',
        },
        role: {
            control: 'select',
            options: ['status', 'alert'],
            description: 'Assistive-tech announcement: polite (status) or assertive (alert).',
        },
        children: { control: 'text' },
    },
} satisfies Meta<typeof Banner>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const Variants: Story = {
    render: () => (
        <div className="grid max-w-xl gap-3">
            <Banner variant="info">
                Members can browse the workspace but only owners can change its settings.
            </Banner>
            <Banner variant="warning">
                This project is past its target date — health projections are based on today.
            </Banner>
            <Banner variant="danger" role="alert">
                « Design system » still contains 34 issues and cannot be deleted.
            </Banner>
        </div>
    ),
};

export const CustomIcon: Story = {
    render: () => (
        <div className="max-w-xl">
            <Banner variant="info" icon={Lock}>
                API tokens of this workspace were rotated an hour ago.
            </Banner>
        </div>
    ),
};

export const NoIcon: Story = {
    render: () => (
        <div className="max-w-xl">
            <Banner variant="info" icon={null}>
                A banner without a leading icon, for messages that repeat often.
            </Banner>
        </div>
    ),
};

export const AlertRole: Story = {
    render: () => (
        <div className="max-w-xl">
            <Banner variant="danger" role="alert">
                Deletion refused — archive the project instead to keep its history.
            </Banner>
        </div>
    ),
};
