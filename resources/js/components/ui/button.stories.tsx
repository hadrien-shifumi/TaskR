/**
 * Story conventions for the Taskr design-system gallery (run `npm run
 * storybook`):
 *
 * - Stories are colocated with their component as `*.stories.tsx` next to
 *   the component file under `resources/js/components/`.
 * - Use CSF3: `satisfies Meta<typeof Component>` plus `StoryObj<typeof meta>`.
 * - Titles: `UI/<Name>` for primitives in `components/ui/`, `Components/<Name>`
 *   for app components in `components/`.
 * - Expose controls (argTypes) for every variant/size so the Playground
 *   story can exercise the full matrix.
 * - Never use raw hex colors — style with Tailwind design tokens only
 *   (bg-primary, text-muted-foreground, border-border, ...).
 * - Indentation: 2 spaces under `components/ui/` (shadcn style), 4 spaces
 *   under `components/`.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';

const meta = {
  title: 'UI/Button',
  component: Button,
  tags: ['autodocs'],
  args: {
    children: 'Button',
    variant: 'default',
    size: 'default',
  },
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'outline', 'secondary', 'ghost', 'destructive', 'link'],
      description: 'Visual style variant.',
    },
    size: {
      control: 'select',
      options: ['default', 'xs', 'sm', 'lg', 'icon', 'icon-xs', 'icon-sm', 'icon-lg'],
      description: 'Height/padding preset; icon* sizes are square.',
    },
  },
} satisfies Meta<typeof Button>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {};

export const Variants: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button variant="default">Default</Button>
      <Button variant="outline">Outline</Button>
      <Button variant="secondary">Secondary</Button>
      <Button variant="ghost">Ghost</Button>
      <Button variant="destructive">Destructive</Button>
      <Button variant="link">Link</Button>
    </div>
  ),
};

export const Sizes: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button size="xs">Extra small</Button>
      <Button size="sm">Small</Button>
      <Button>Default size</Button>
      <Button size="lg">Large</Button>
      <Button size="icon-xs" aria-label="Add">
        <Plus />
      </Button>
      <Button size="icon-sm" aria-label="Add">
        <Plus />
      </Button>
      <Button size="icon" aria-label="Add">
        <Plus />
      </Button>
      <Button size="icon-lg" aria-label="Add">
        <Plus />
      </Button>
    </div>
  ),
};

export const Disabled: Story = {
  args: {
    disabled: true,
  },
};
