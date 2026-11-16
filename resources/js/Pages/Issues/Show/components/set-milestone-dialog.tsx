import { Banner } from '@/components/banner';
import { pendingMilestoneDescendantsCount } from '@/components/cascade-move-dialog';
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
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { MilestoneOption } from '@/types';
import type { IssueDetail } from '../types';

type SetMilestoneDialogProps = {
    issue: IssueDetail;
    /** Milestones of the issue's project, offered as options. */
    options: MilestoneOption[];
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-parent pattern applied to the milestone: one
 * project milestone at most, "none" detaches. The same-project rule
 * lives in the action; the 422 refusal surfaces as a banner-danger
 * inside the dialog.
 *
 * When the chosen milestone does not yet hold every active sub-ticket,
 * one confirmation asks before the single POST whether the whole
 * sub-tree follows — the server enforces the rule either way, a refusal
 * is a no-op.
 */
export function SetMilestoneDialog({ issue, options, open, onClose }: SetMilestoneDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <SetMilestoneForm issue={issue} options={options} onClose={onClose} /> : null}
        </Dialog>
    );
}

function SetMilestoneForm({
    issue,
    options,
    onClose,
}: {
    issue: IssueDetail;
    options: MilestoneOption[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [milestoneId, setMilestoneId] = useState<string>(issue.milestone?.id ?? 'none');
    const [refused, setRefused] = useState<string | null>(null);
    /** Active sub-tickets outside the chosen milestone: the pending confirmation. */
    const [pendingCascade, setPendingCascade] = useState<number | null>(null);

    const targetMilestoneId = milestoneId === 'none' ? null : milestoneId;
    const targetMilestoneTitle =
        options.find((option) => option.id === targetMilestoneId)?.title ?? t('issueShow.noMilestone');

    const setMilestone = (cascade: boolean) => {
        setRefused(null);
        router.post(
            route('issues.milestone', issue.id),
            { milestone_id: targetMilestoneId, cascade_children: cascade },
            {
                onSuccess: () => onClose(),
                // The same-project refusal is a validation error; a
                // permission refusal is a plain HTTP exception.
                onError: (errors) => setRefused(errors.milestone_id ?? t('issueShow.milestoneError')),
                onHttpException: () => {
                    setRefused(t('issueShow.milestoneError'));
                    return false;
                },
            },
        );
    };

    // Detaching never cascades; attaching asks once when active
    // sub-tickets are outside the target milestone.
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setRefused(null);

        if (targetMilestoneId === null) {
            setMilestone(false);
            return;
        }

        const count = pendingMilestoneDescendantsCount(issue.descendants, targetMilestoneId);
        if (count > 0) {
            setPendingCascade(count);
        } else {
            setMilestone(false);
        }
    };

    if (pendingCascade !== null) {
        return (
            <DialogContent data-test="milestone-cascade-dialog" className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('issueShow.milestoneCascadeTitle')}</DialogTitle>
                    <DialogDescription>
                        {pendingCascade === 1
                            ? t('issueShow.milestoneCascadeDescriptionOne', {
                                  milestone: targetMilestoneTitle,
                              })
                            : t('issueShow.milestoneCascadeDescriptionMany', {
                                  count: pendingCascade,
                                  milestone: targetMilestoneTitle,
                              })}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        data-test="milestone-cascade-decline"
                        onClick={() => {
                            const count = pendingCascade;
                            setPendingCascade(null);
                            if (count !== null) setMilestone(false);
                        }}
                    >
                        {t('issueShow.milestoneCascadeDecline')}
                    </Button>
                    <Button
                        data-test="milestone-cascade-accept"
                        onClick={() => {
                            const count = pendingCascade;
                            setPendingCascade(null);
                            if (count !== null) setMilestone(true);
                        }}
                    >
                        {t('issueShow.milestoneCascadeAccept')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        );
    }

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('issueShow.milestoneTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.milestoneDialogHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="milestone-select">{t('issueShow.milestone')}</Label>
                    <Select
                        value={milestoneId}
                        onValueChange={(value) => {
                            if (value) {
                                setMilestoneId(value);
                                setRefused(null);
                            }
                        }}
                    >
                        <SelectTrigger id="milestone-select" data-test="milestone-select" className="w-full">
                            <SelectValue>
                                {milestoneId === 'none'
                                    ? t('issueShow.noMilestone')
                                    : (options.find((option) => option.id === milestoneId)?.title ??
                                      t('issueShow.noMilestone'))}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{t('issueShow.noMilestone')}</SelectItem>
                            {options.map((option) => (
                                <SelectItem key={option.id} value={option.id}>
                                    {option.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {refused ? (
                    <Banner variant="danger" role="alert" data-test="milestone-error">
                        <strong>{t('issueShow.milestoneRefused')}</strong> {refused}
                    </Banner>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="milestone-submit">
                        {t('common.save')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
