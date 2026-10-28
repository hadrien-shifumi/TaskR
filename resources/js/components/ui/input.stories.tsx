import type { Meta, StoryObj } from '@storybook/react'

import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const meta = {
  title: 'UI/Input',
  component: Input,
  tags: ['autodocs'],
  args: {
    type: 'text',
    placeholder: 'Search issues…',
    className: 'max-w-xs',
  },
  argTypes: {
    type: {
      control: 'select',
      options: ['text', 'email', 'password', 'search', 'number', 'url'],
      description: 'Native input type.',
    },
  },
} satisfies Meta<typeof Input>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {}

export const Placeholder: Story = {
  args: {
    type: 'email',
    placeholder: 'ada@example.com',
  },
}

export const Disabled: Story = {
  args: {
    disabled: true,
    defaultValue: 'Studio Nova',
    placeholder: 'Workspace name',
  },
}

export const Invalid: Story = {
  render: (args) => (
    <div className="grid max-w-xs gap-2">
      <Label htmlFor="input-invalid">Workspace name</Label>
      <Input {...args} id="input-invalid" aria-invalid placeholder="Required field" />
      <p className="text-sm text-destructive">The workspace name is required.</p>
    </div>
  ),
}
