import type { Meta, StoryObj } from '@storybook/react'
import { useState } from 'react'

import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'

const meta = {
  title: 'UI/Checkbox',
  component: Checkbox,
  tags: ['autodocs'],
  args: {
    'aria-label': 'Select issue',
  },
  argTypes: {
    disabled: {
      control: 'boolean',
      description: 'Dims the box and blocks interaction.',
    },
  },
} satisfies Meta<typeof Checkbox>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Unchecked: Story = {}

export const Checked: Story = {
  render: () => {
    const [checked, setChecked] = useState(true)

    return (
      <Checkbox
        aria-label="Toggle"
        checked={checked}
        onCheckedChange={(value) => setChecked(value === true)}
      />
    )
  },
}

export const Disabled: Story = {
  render: () => (
    <div className="flex items-center gap-3">
      <Checkbox disabled aria-label="Disabled unchecked" />
      <Checkbox disabled defaultChecked aria-label="Disabled checked" />
    </div>
  ),
}

export const WithLabel: Story = {
  render: () => {
    const [checked, setChecked] = useState(false)

    return (
      <div className="flex items-center gap-2">
        <Checkbox
          id="checkbox-notifications"
          checked={checked}
          onCheckedChange={(value) => setChecked(value === true)}
        />
        <Label htmlFor="checkbox-notifications">
          Email me when an issue is assigned to me
        </Label>
      </div>
    )
  },
}
