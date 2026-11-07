import { Button } from '@/components/ui/button';
import { MarkdownView } from '@/components/ui/markdown-view';
import { Textarea } from '@/components/ui/textarea';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';
import { SectionHead } from './section-head';

type DescriptionSectionProps = {
    issue: IssueDetail;
};

/**
 * Maquette issue-description: rendered markdown, inline edition (textarea,
 * save/cancel, "edited by" meta) and a markdown preview toggle.
 */
export function DescriptionSection({ issue }: DescriptionSectionProps) {
    const { auth } = usePage<{ auth: { user: { id: number; name: string } | null } }>().props;
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [previewing, setPreviewing] = useState(false);

    // The update action requires the title and rewrites every field it
    // knows: the untouched fields always travel along with the description.
    const descriptionForm = useForm({
        title: issue.title,
        description: issue.description ?? '',
        priority: issue.priority,
        due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
    });

    const startEditing = () => {
        descriptionForm.setData({
            title: issue.title,
            description: issue.description ?? '',
            priority: issue.priority,
            due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
        });
        descriptionForm.clearErrors();
        setPreviewing(false);
        setEditing(true);
    };

    const submitDescription = (e: React.FormEvent) => {
        e.preventDefault();
        descriptionForm.patch(route('issues.update', issue.id), {
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <section>
            <SectionHead
                title={t('issueShow.description')}
                action={
                    editing ? undefined : (
                        <Button variant="ghost" size="sm" data-test="description-edit" onClick={startEditing}>
                            {t('issueShow.edit')}
                        </Button>
                    )
                }
            />
            {editing ? (
                <form onSubmit={submitDescription} className="grid gap-2.5">
                    <p className="font-mono text-xs text-muted-foreground">
                        {t('issueShow.markdownHint')}
                    </p>
                    {previewing ? (
                        <div
                            data-test="description-preview-view"
                            className="min-h-[170px] rounded-lg border bg-card px-3.5 py-2.5"
                        >
                            {descriptionForm.data.description ? (
                                <MarkdownView value={descriptionForm.data.description} className="text-[14.5px]" />
                            ) : (
                                <p className="text-sm italic text-muted-foreground">{t('issueShow.nothingToPreview')}</p>
                            )}
                        </div>
                    ) : (
                        <Textarea
                            data-test="description-edit-body"
                            className="min-h-[170px]"
                            value={descriptionForm.data.description}
                            onChange={(e) => descriptionForm.setData('description', e.target.value)}
                            aria-invalid={descriptionForm.errors.description ? true : undefined}
                        />
                    )}
                    {descriptionForm.errors.description ? (
                        <p className="text-sm text-destructive">{descriptionForm.errors.description}</p>
                    ) : null}
                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            data-test="description-edit-save"
                            disabled={descriptionForm.processing}
                        >
                            {t('common.save')}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="description-preview"
                            onClick={() => setPreviewing((value) => !value)}
                        >
                            {previewing ? t('issueShow.write') : t('issueShow.preview')}
                        </Button>
                        <span className="ml-auto text-xs text-muted-foreground">
                            {t('issueShow.editedBy', { name: auth.user?.name ?? 'you' })}
                        </span>
                    </div>
                </form>
            ) : issue.description ? (
                <MarkdownView value={issue.description} className="text-[14.5px] leading-relaxed" />
            ) : (
                <p className="text-sm italic text-muted-foreground">{t('issueShow.noDescription')}</p>
            )}
        </section>
    );
}
