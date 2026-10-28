import type { Meta, StoryObj } from '@storybook/react'

import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'

const meta = {
  title: 'UI/Textarea',
  component: Textarea,
  tags: ['autodocs'],
  args: {
    placeholder: 'Describe the issue…',
    className: 'max-w-sm',
  },
  argTypes: {
    disabled: {
      control: 'boolean',
      description: 'Dims the field and blocks interaction.',
    },
    readOnly: {
      control: 'boolean',
    },
  },
} satisfies Meta<typeof Textarea>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {}

export const Placeholder: Story = {
  args: {
    placeholder: 'Short project description…',
  },
}

export const Disabled: Story = {
  args: {
    disabled: true,
    defaultValue: 'Read-only description.',
    placeholder: 'Describe the issue…',
  },
}

export const Invalid: Story = {
  render: (args) => (
    <div className="grid max-w-sm gap-2">
      <Label htmlFor="textarea-invalid">Project description</Label>
      <Textarea {...args} id="textarea-invalid" aria-invalid />
      <p className="text-sm text-destructive">The description cannot exceed 500 characters.</p>
    </div>
  ),
}
