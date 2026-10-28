import type { Meta, StoryObj } from '@storybook/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'

const meta = {
  title: 'UI/Dialog',
  component: Dialog,
  tags: ['autodocs'],
} satisfies Meta<typeof Dialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Uncontrolled: Story = {
  render: () => (
    <Dialog>
      <DialogTrigger render={<Button variant="outline" />}>Delete project</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete project?</DialogTitle>
          <DialogDescription>
            The project Aurora and its 12 issues will be permanently removed. This action cannot
            be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose render={<Button variant="ghost" />}>Cancel</DialogClose>
          <DialogClose render={<Button variant="destructive" />}>Delete</DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
}

export const Controlled: Story = {
  render: () => {
    const [open, setOpen] = useState(false)

    return (
      <div className="flex flex-col items-start gap-2">
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger render={<Button variant="outline" />}>
            Open controlled dialog
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Controlled dialog</DialogTitle>
              <DialogDescription>
                The open state lives in a useState hook and flows through open / onOpenChange.
                Clicking the backdrop never closes it.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setOpen(false)}>
                Close
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
        <p className="text-sm text-muted-foreground">open: {String(open)}</p>
      </div>
    )
  },
}

export const WithFooterCloseButton: Story = {
  render: () => (
    <Dialog>
      <DialogTrigger render={<Button variant="outline" />}>Workspace settings</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Workspace settings</DialogTitle>
          <DialogDescription>
            The footer accepts a showCloseButton prop rendering an extra outline Close action.
          </DialogDescription>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">
          Workspace name, icon and default language live here.
        </p>
        <DialogFooter showCloseButton>
          <Button>Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
}
