import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

type FormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    submitLabel: string;
    processing?: boolean;
    /** Extra classes on the content panel, e.g. for wide or scrollable forms. */
    contentClassName?: string;
    /** Extra classes on the fields container. */
    bodyClassName?: string;
    /**
     * Keeps the header and the footer (submit button) pinned to the panel and
     * scrolls only the fields. Pair it with a `max-h-*` on `contentClassName`.
     */
    scrollableBody?: boolean;
    onSubmit: (e: React.FormEvent) => void;
    children: React.ReactNode;
};

/**
 * Dialog shell for Inertia forms: header, fields, footer with a submit button
 * wired to `processing`. Field errors stay in `children`, next to their input.
 *
 * With `scrollableBody`, the panel becomes a column capped by its
 * `contentClassName` height: the header and the footer never scroll, only the
 * fields container does.
 */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    processing = false,
    contentClassName,
    bodyClassName,
    scrollableBody = false,
    onSubmit,
    children,
}: FormDialogProps) {
    const header = (
        <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
    );

    const footer = (
        <DialogFooter>
            <Button type="submit" disabled={processing}>
                {submitLabel}
            </Button>
        </DialogFooter>
    );

    if (!scrollableBody) {
        return (
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className={contentClassName}>
                    {header}
                    <form onSubmit={onSubmit} className={cn('grid gap-4', bodyClassName)}>
                        {children}
                        {footer}
                    </form>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('flex flex-col gap-4 overflow-hidden', contentClassName)}>
                {header}
                <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div
                        data-test="form-dialog-body"
                        className={cn('min-h-0 flex-1 overflow-y-auto', bodyClassName)}
                    >
                        {children}
                    </div>
                    {/* The footer keeps the panel padding as a margin: the
                        negative margins of DialogFooter would otherwise sit
                        outside the scrolling body and get clipped. */}
                    <div className="mt-4 shrink-0">{footer}</div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
