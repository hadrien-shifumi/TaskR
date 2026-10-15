import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type FormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    submitLabel: string;
    processing?: boolean;
    /** Extra classes on the content panel, e.g. for wide or scrollable forms. */
    contentClassName?: string;
    onSubmit: (e: React.FormEvent) => void;
    children: React.ReactNode;
};

/**
 * Dialog shell for Inertia forms: header, `<form>` grid, footer with a
 * submit button wired to `processing`. Field errors stay in `children`,
 * next to their input.
 */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    processing = false,
    contentClassName,
    onSubmit,
    children,
}: FormDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={contentClassName}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <form onSubmit={onSubmit} className="grid gap-4">
                    {children}
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
