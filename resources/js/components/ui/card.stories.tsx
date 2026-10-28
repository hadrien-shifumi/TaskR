import type { Meta, StoryObj } from '@storybook/react'
import { Plus } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'

const meta = {
  title: 'UI/Card',
  component: Card,
  tags: ['autodocs'],
  argTypes: {
    size: {
      control: 'select',
      options: ['default', 'sm'],
      description: 'Drives the internal --card-spacing variable (4 / 3).',
    },
  },
} satisfies Meta<typeof Card>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Composition: Story = {
  args: {
    size: 'default',
  },
  render: (args) => (
    <Card className="w-80" {...args}>
      <CardHeader>
        <CardTitle>Storage</CardTitle>
        <CardDescription>Attachments across all projects.</CardDescription>
        <CardAction>
          <Button variant="outline" size="icon-sm" aria-label="Buy storage">
            <Plus />
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        <p className="text-2xl font-semibold">2.4 GB</p>
        <p className="text-muted-foreground">of 10 GB used</p>
      </CardContent>
      <CardFooter>
        <p className="text-xs text-muted-foreground">Updated 2 minutes ago</p>
      </CardFooter>
    </Card>
  ),
}

export const Small: Story = {
  args: {
    size: 'sm',
  },
  render: (args) => (
    <Card className="w-72" {...args}>
      <CardHeader>
        <CardTitle>Open issues</CardTitle>
        <CardDescription>Across every project.</CardDescription>
      </CardHeader>
      <CardContent>
        <p className="text-2xl font-semibold">37</p>
        <p className="text-success">6 closed this week</p>
      </CardContent>
    </Card>
  ),
}

export const Minimal: Story = {
  render: () => (
    <Card className="w-72">
      <CardContent>
        <p className="font-medium">A bare card</p>
        <p className="text-muted-foreground">
          Header and footer are optional — a Card can hold plain content.
        </p>
      </CardContent>
    </Card>
  ),
}
