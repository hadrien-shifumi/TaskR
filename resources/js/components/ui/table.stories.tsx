import type { Meta, StoryObj } from '@storybook/react'

import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

const ISSUES = [
  {
    reference: 'TSK-104',
    title: 'Fix flaky board drag-and-drop',
    status: 'In progress',
    assignee: 'Ada Lovelace',
  },
  {
    reference: 'TSK-98',
    title: 'Add keyboard shortcuts to the issue list',
    status: 'Backlog',
    assignee: 'Grace Hopper',
  },
  {
    reference: 'TSK-91',
    title: 'Migrate avatars to object storage',
    status: 'Done',
    assignee: 'Alan Turing',
  },
]

function TableHeads() {
  return (
    <TableRow>
      <TableHead className="w-24">Ref</TableHead>
      <TableHead>Title</TableHead>
      <TableHead className="w-28">Status</TableHead>
      <TableHead className="w-40">Assignee</TableHead>
    </TableRow>
  )
}

const meta = {
  title: 'UI/Table',
  component: Table,
  tags: ['autodocs'],
} satisfies Meta<typeof Table>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Basic: Story = {
  render: () => (
    <Table>
      <TableCaption>Issues assigned to you — rows highlight on hover.</TableCaption>
      <TableHeader>
        <TableHeads />
      </TableHeader>
      <TableBody>
        {ISSUES.map((issue) => (
          <TableRow key={issue.reference}>
            <TableCell className="font-mono text-xs text-muted-foreground">
              {issue.reference}
            </TableCell>
            <TableCell className="font-medium">{issue.title}</TableCell>
            <TableCell>
              <Badge variant="secondary">{issue.status}</Badge>
            </TableCell>
            <TableCell className="text-muted-foreground">{issue.assignee}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  ),
}

export const EmptyState: Story = {
  render: () => (
    <Table>
      <TableHeader>
        <TableHeads />
      </TableHeader>
      <TableBody>
        <TableRow>
          <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
            No issues match these filters.
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  ),
}
