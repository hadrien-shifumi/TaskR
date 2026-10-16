import { Banner } from '@/components/banner';
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
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
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
    const { t } = useTranslation();
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
                    setMigrationError(t('settings.migrationRefused'));
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
            setMigrationError(t('settings.chooseFirst'));
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
                        ? t('settings.receivedNewIssues', { name: status.name })
                        : t('settings.deletionRefused'),
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
                                ? migrateIds.length === 1
                                    ? t('settings.usedByIssues', { name: status.name, count: migrateIds.length })
                                    : t('settings.usedByIssuesPlural', { name: status.name, count: migrateIds.length })
                                : t('settings.deleteStatusTitle', { name: status.name })}
                        </DialogTitle>
                        <DialogDescription>
                            {migrateIds.length > 0
                                ? t('settings.deleteStatusRefusedDescription')
                                : t('settings.noIssuesUseStatus')}
                        </DialogDescription>
                    </DialogHeader>

                    {migrateIds.length > 0 ? (
                        <div className="grid gap-3.5">
                            <Banner variant="danger">
                                {t('settings.migrateBanner', {
                                    issues:
                                        migrateIds.length === 1
                                            ? t('settings.thisIssue')
                                            : t('settings.migrateIssueList', { count: migrateIds.length }),
                                    name: status.name,
                                })}
                            </Banner>
                            <div className="grid gap-2">
                                <Label>{t('settings.moveIssuesTo')}</Label>
                                <Select
                                    value={migrateTo}
                                    onValueChange={(value) => {
                                        if (value) setMigrateTo(value);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder={t('settings.chooseStatus')} />
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
                            {t('common.cancel')}
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
                            {migrateIds.length > 0 ? t('settings.moveThenDelete') : t('common.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}
