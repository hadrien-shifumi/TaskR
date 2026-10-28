import type { Meta, StoryObj } from '@storybook/react'
import { useState } from 'react'

import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

const STATUSES = [
  { value: 'backlog', label: 'Backlog' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'done', label: 'Done' },
]

const PEOPLE = [
  { value: 'ada', label: 'Ada Lovelace', group: 'Team' },
  { value: 'grace', label: 'Grace Hopper', group: 'Team' },
  { value: 'alan', label: 'Alan Turing', group: 'Reviewers' },
]

const meta = {
  title: 'UI/Select',
  component: Select,
  tags: ['autodocs'],
} satisfies Meta<typeof Select>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Playground: Story = {
  render: () => {
    const [status, setStatus] = useState('in_progress')

    return (
      <div className="flex max-w-xs flex-col gap-2">
        <Label htmlFor="select-status">Status</Label>
        <Select
          value={status}
          onValueChange={(value) => {
            if (value) setStatus(value);
          }}
        >
          <SelectTrigger id="select-status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUSES.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-sm text-muted-foreground">Selected: {status}</p>
      </div>
    )
  },
}

export const WithPlaceholder: Story = {
  render: () => (
    <Select>
      <SelectTrigger className="w-44">
        <SelectValue placeholder="Select a status" />
      </SelectTrigger>
      <SelectContent>
        {STATUSES.map((item) => (
          <SelectItem key={item.value} value={item.value}>
            {item.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  ),
}

export const Disabled: Story = {
  render: () => (
    <Select defaultValue="backlog" disabled>
      <SelectTrigger className="w-44">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {STATUSES.map((item) => (
          <SelectItem key={item.value} value={item.value}>
            {item.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  ),
}

export const WithGroups: Story = {
  render: () => (
    <Select>
      <SelectTrigger className="w-44">
        <SelectValue placeholder="Assign to…" />
      </SelectTrigger>
      <SelectContent>
        {['Team', 'Reviewers'].map((group) => (
          <SelectGroup key={group}>
            <SelectLabel>{group}</SelectLabel>
            {PEOPLE.filter((person) => person.group === group).map((person) => (
              <SelectItem key={person.value} value={person.value}>
                {person.label}
              </SelectItem>
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  ),
}
