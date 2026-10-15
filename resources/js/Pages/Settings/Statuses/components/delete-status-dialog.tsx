import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { StatusRow } from '../types';

type DeleteStatusDialogProps = {
    /** Status being deleted, or null when the dialog is closed. */
    status: StatusRow | null;
    statuses: StatusRow[];
    onClose: () => void;
};

/**
 * Deleting a used status is refused by the action. The dialog composes
 * existing actions instead: move each issue to the chosen status, then
 * delete. If the state changed in the meantime the action refuses and
 * the error surfaces here — no client-side copy of the rule.
 */
export function DeleteStatusDialog({ status, statuses, onClose }: DeleteStatusDialogProps) {
    const [migrateIds, setMigrateIds] = useState<string[]>([]);
    const [migrateTo, setMigrateTo] = useState('');
    const [migrationError, setMigrationError] = useState<string | null>(null);
    const [migrating, setMigrating] = useState(false);

    // Reset the migration state each time the dialog opens for a status.
    useEffect(() => {
        setMigrateIds(status?.issueIds ?? []);
        setMigrateTo('');
        setMigrationError(null);
    }, [status]);

    const moveOne = (issueId: string, statusId: string) =>
        new Promise<boolean>((resolve) => {
            router.post(route('issues.move', issueId), { status_id: statusId }, {
                onSuccess: () => resolve(true),
                onHttpException: () => {
                    setMigrationError('The migration was refused. Reload and retry.');
                    resolve(false);
                    return false;
                },
            });
        });

    const confirmDelete = async () => {
        if (!status) return;

        if (migrateIds.length === 0) {
            router.delete(route('statuses.destroy', status.id), {
                onSuccess: () => onClose(),
            });
            return;
        }

        if (!migrateTo) {
            setMigrationError('Choose the status the issues move to first.');
            return;
        }

        setMigrating(true);
        for (const issueId of migrateIds) {
            const moved = await moveOne(issueId, migrateTo);
            if (!moved) {
                setMigrating(false);
                return;
            }
        }

        router.delete(route('statuses.destroy', status.id), {
            onHttpException: (response) => {
                setMigrationError(
                    response.status === 409
                        ? `Refused — "${status.name}" received new issues since the page loaded. Retry the migration.`
                        : 'The deletion was refused.',
                );
                setMigrating(false);
                return false;
            },
            onSuccess: () => {
                setMigrating(false);
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={status !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {status ? (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {migrateIds.length > 0
                                ? `"${status.name}" is used by ${migrateIds.length} ${
                                      migrateIds.length === 1 ? 'issue' : 'issues'
                                  }`
                                : `Delete "${status.name}"?`}
                        </DialogTitle>
                        <DialogDescription>
                            {migrateIds.length > 0
                                ? 'Deletion is refused while issues use this status.'
                                : 'No issue uses this status.'}
                        </DialogDescription>
                    </DialogHeader>

                    {migrateIds.length > 0 ? (
                        <div className="grid gap-3.5">
                            <p className="flex items-start gap-2.5 rounded-lg border border-destructive/35 bg-destructive/10 px-3.5 py-3 text-sm text-destructive">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    Move {migrateIds.length === 1 ? 'this issue' : `these ${migrateIds.length} issues`} to
                                    another status first, then delete “{status.name}”.
                                </span>
                            </p>
                            <div className="grid gap-2">
                                <Label>Move the issues to</Label>
                                <Select
                                    value={migrateTo}
                                    onValueChange={(value) => {
                                        if (value) setMigrateTo(value);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Choose a status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses
                                            .filter((candidate) => candidate.id !== status.id)
                                            .map((candidate) => (
                                                <SelectItem key={candidate.id} value={candidate.id}>
                                                    {candidate.name}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {migrationError ? (
                                <p role="alert" className="text-sm text-destructive">
                                    {migrationError}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            variant={migrateIds.length > 0 ? 'outline' : 'destructive'}
                            data-test="confirm-delete-status"
                            className={
                                migrateIds.length > 0
                                    ? 'border-destructive/35 text-destructive hover:bg-destructive/10'
                                    : undefined
                            }
                            disabled={migrating}
                            onClick={() => confirmDelete()}
                        >
                            {migrateIds.length > 0 ? 'Move then delete' : 'Delete'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}
