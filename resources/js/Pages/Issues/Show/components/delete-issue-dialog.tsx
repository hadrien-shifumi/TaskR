import { Banner } from '@/components/banner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';
import { formatHours } from './format';

type DeleteIssueDialogProps = {
    /** Issue being deleted, or null when the dialog is closed. */
    issue: IssueDetail | null;
    commentsCount: number;
    onClose: () => void;
};

/**
 * Maquette dlg-delete-issue: a banner-danger lists the cascade inventory —
 * logged time and comments are lost, child issues become root issues.
 */
export function DeleteIssueDialog({ issue, commentsCount, onClose }: DeleteIssueDialogProps) {
    const { t } = useTranslation();

    const confirmDelete = () => {
        if (!issue) return;
        router.delete(route('issues.destroy', issue.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open={issue !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {issue ? (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('issueShow.deleteTitle')}</DialogTitle>
                        <DialogDescription>“{issue.title}”</DialogDescription>
                    </DialogHeader>
                    <CascadeBanner issue={issue} commentsCount={commentsCount} />
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" data-test="confirm-delete-issue" onClick={confirmDelete}>
                            {t('common.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

function CascadeBanner({ issue, commentsCount }: { issue: IssueDetail; commentsCount: number }) {
    const { t } = useTranslation();
    const lost: string[] = [];
    if (issue.loggedHours > 0) lost.push(t('issueShow.loggedTime', { hours: formatHours(issue.loggedHours) }));
    if (commentsCount > 0)
        lost.push(
            commentsCount === 1
                ? t('issueShow.commentCount', { count: commentsCount })
                : t('issueShow.commentCountPlural', { count: commentsCount }),
        );

    return (
        <Banner variant="danger">
            {lost.length > 0 ? (
                <>{t('issueShow.deletionAlsoRemoves', { items: lost.join(' and ') })}</>
            ) : (
                <>{t('issueShow.cannotUndo')}</>
            )}
            {issue.children.length > 0
                ? issue.children.length === 1
                    ? t('issueShow.childBecomesRoot', { count: issue.children.length })
                    : t('issueShow.childrenBecomeRoots', { count: issue.children.length })
                : ''}
        </Banner>
    );
}
