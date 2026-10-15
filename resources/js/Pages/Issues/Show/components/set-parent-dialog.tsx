import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { IssueDetail } from '../types';

type SetParentDialogProps = {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-parent: same-workspace candidates only, cycles refused.
 * The cycle rule lives in the action (ADR-5); the 422 refusal surfaces as a
 * banner-danger inside the dialog.
 */
export function SetParentDialog({ issue, candidates, open, onClose }: SetParentDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <SetParentForm issue={issue} candidates={candidates} onClose={onClose} /> : null}
        </Dialog>
    );
}

function SetParentForm({
    issue,
    candidates,
    onClose,
}: {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    onClose: () => void;
}) {
    const [parentId, setParentId] = useState<string>(issue.parent?.id ?? 'none');
    const [cycleError, setCycleError] = useState<string | null>(null);

    const setParent = (e: React.FormEvent) => {
        e.preventDefault();
        setCycleError(null);
        router.post(
            route('issues.parent', issue.id),
            { parent_id: parentId === 'none' ? null : parentId },
            {
                onSuccess: () => onClose(),
                onHttpException: (response) => {
                    setCycleError(
                        response.status === 422
                            ? 'this issue is a direct child — making it the parent would create a cycle. A parent must also belong to the same workspace.'
                            : 'the parent could not be set.',
                    );
                    return false;
                },
            },
        );
    };

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Set parent issue</DialogTitle>
                <DialogDescription>Same workspace only · cycles are forbidden.</DialogDescription>
            </DialogHeader>
            <form onSubmit={setParent} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="parent-select">Parent issue</Label>
                    <Select
                        value={parentId}
                        onValueChange={(value) => {
                            if (value) {
                                setParentId(value);
                                setCycleError(null);
                            }
                        }}
                    >
                        <SelectTrigger id="parent-select" data-test="parent-select" className="w-full">
                            <SelectValue>
                                {parentId === 'none'
                                    ? 'No parent'
                                    : (candidates.find((c) => c.id === parentId)?.title ?? 'No parent')}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No parent</SelectItem>
                            {candidates.map((candidate) => (
                                <SelectItem key={candidate.id} value={candidate.id}>
                                    {candidate.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {cycleError ? (
                    <p
                        role="alert"
                        data-test="cycle-error"
                        className="flex items-start gap-2.5 rounded-lg border border-destructive/35 bg-destructive/10 px-3.5 py-3 text-sm text-destructive"
                    >
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                        <span>
                            <strong>Attachment refused:</strong> {cycleError}
                        </span>
                    </p>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" data-test="parent-submit">
                        Set parent
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
