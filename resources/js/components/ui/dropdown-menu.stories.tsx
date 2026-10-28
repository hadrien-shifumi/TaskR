import type { Meta, StoryObj } from '@storybook/react'
import { Archive, Copy, MoreHorizontal, Pencil, Trash2, UserPlus } from 'lucide-react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

const meta = {
  title: 'UI/DropdownMenu',
  component: DropdownMenu,
  tags: ['autodocs'],
} satisfies Meta<typeof DropdownMenu>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Actions: Story = {
  render: () => (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={<Button variant="ghost" size="icon-sm" aria-label="Issue actions" />}
      >
        <MoreHorizontal />
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuLabel>Issue actions</DropdownMenuLabel>
        <DropdownMenuGroup>
          <DropdownMenuItem>
            <Pencil />
            Edit
            <DropdownMenuShortcut>⌘E</DropdownMenuShortcut>
          </DropdownMenuItem>
          <DropdownMenuItem>
            <Copy />
            Duplicate
            <DropdownMenuShortcut>⌘D</DropdownMenuShortcut>
          </DropdownMenuItem>
          <DropdownMenuItem disabled>
            <UserPlus />
            Assign
          </DropdownMenuItem>
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <DropdownMenuItem>
            <Archive />
            Archive
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive">
            <Trash2 />
            Delete
            <DropdownMenuShortcut>⌘⌫</DropdownMenuShortcut>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  ),
}

export const WithCheckboxItems: Story = {
  render: () => {
    const [showDone, setShowDone] = useState(true)
    const [compact, setCompact] = useState(false)

    return (
      <DropdownMenu>
        <DropdownMenuTrigger render={<Button variant="outline" />}>
          View options
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuLabel>Board display</DropdownMenuLabel>
          <DropdownMenuCheckboxItem
            checked={showDone}
            onCheckedChange={(value) => setShowDone(value === true)}
          >
            Show done issues
          </DropdownMenuCheckboxItem>
          <DropdownMenuCheckboxItem
            checked={compact}
            onCheckedChange={(value) => setCompact(value === true)}
          >
            Compact rows
          </DropdownMenuCheckboxItem>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  },
}

export const WithRadioGroup: Story = {
  render: () => {
    const [sort, setSort] = useState('newest')

    return (
      <DropdownMenu>
        <DropdownMenuTrigger render={<Button variant="outline" />}>Sort</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuRadioGroup
            value={sort}
            onValueChange={(value) => setSort(String(value))}
          >
            <DropdownMenuRadioItem value="newest">Newest first</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="oldest">Oldest first</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="priority">By priority</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  },
}
