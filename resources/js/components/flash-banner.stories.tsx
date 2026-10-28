/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * FlashBanner reads the shared Inertia props — each story decorator seeds
 * `flash` through the Storybook mock so stories stay order-independent.
 */
import type { Decorator, Meta, StoryObj } from '@storybook/react';

import { FlashBanner } from '@/components/flash-banner';
import { setInertiaSharedProps } from '../../../.storybook/mocks/inertia-react';

const withFlash = (status?: string): Decorator => (Story) => {
    setInertiaSharedProps({ flash: status ? { status } : {} });

    return <Story />;
};

const meta = {
    title: 'Components/FlashBanner',
    component: FlashBanner,
    tags: ['autodocs'],
    argTypes: {
        className: { control: 'text' },
    },
} satisfies Meta<typeof FlashBanner>;

export default meta;
type Story = StoryObj<typeof meta>;

export const WithFlash: Story = {
    name: 'Flash message present',
    decorators: [withFlash('Label « Documentation » created.')],
    render: () => <FlashBanner />,
};

export const WithoutFlash: Story = {
    name: 'Empty flash (renders nothing)',
    decorators: [withFlash(undefined)],
    render: () => (
        <div className="grid gap-1">
            <FlashBanner />
            <p className="text-sm text-muted-foreground">↑ Nothing renders while flash.status is empty.</p>
        </div>
    ),
};
