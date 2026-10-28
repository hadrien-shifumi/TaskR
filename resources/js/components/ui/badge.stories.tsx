import type { Meta, StoryObj } from '@storybook/react'
import { Check, TriangleAlert } from 'lucide-react'

import { Badge } from '@/components/ui/badge'

const meta = {
  title: 'UI/Badge',
  component: Badge,
  tags: ['autodocs'],
  args: {
    children: 'Badge',
    variant: 'default',
  },
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'secondary', 'destructive', 'outline', 'ghost', 'link'],
      description: 'Visual style variant.',
    },
  },
} satisfies Meta<typeof Badge>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {}

export const Variants: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Badge>Default</Badge>
      <Badge variant="secondary">Secondary</Badge>
      <Badge variant="destructive">Destructive</Badge>
      <Badge variant="outline">Outline</Badge>
      <Badge variant="ghost">Ghost</Badge>
      <Badge variant="link">Link</Badge>
    </div>
  ),
}

export const WithIcon: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Badge variant="secondary" className="rounded-full bg-info/10 text-info">
        <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
        In progress
      </Badge>
      <Badge variant="secondary" className="rounded-full bg-success/10 text-success">
        <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
        Done
      </Badge>
      <Badge variant="destructive" className="rounded-full">
        <TriangleAlert aria-hidden="true" />
        Overdue
      </Badge>
      <Badge variant="outline">
        <Check aria-hidden="true" />
        Merged
      </Badge>
    </div>
  ),
}

export const LongText: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Badge className="max-w-44">Refactoring of the workspace onboarding flow</Badge>
      <Badge className="max-w-44">
        <span className="min-w-0 truncate">
          Refactoring of the workspace onboarding flow
        </span>
      </Badge>
    </div>
  ),
}
