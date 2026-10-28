import type { Meta, StoryObj } from '@storybook/react'

import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const meta = {
  title: 'UI/Label',
  component: Label,
  tags: ['autodocs'],
  args: {
    children: 'Email address',
  },
} satisfies Meta<typeof Label>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {
  render: () => (
    <div className="grid max-w-xs gap-2">
      <Label htmlFor="label-email">Email address</Label>
      <Input id="label-email" type="email" placeholder="ada@example.com" />
    </div>
  ),
}

export const WithDisabledInput: Story = {
  render: () => (
    <div className="grid max-w-xs gap-2">
      <Label htmlFor="label-token">API token</Label>
      <Input id="label-token" defaultValue="tk_live_9f3ac2d1" disabled />
    </div>
  ),
}
