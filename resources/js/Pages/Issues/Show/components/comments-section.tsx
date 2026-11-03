import { Avatar } from '@/components/issue-badges';
import { MentionAutocomplete } from '@/components/mention-autocomplete';
import { Button } from '@/components/ui/button';
import { MarkdownView } from '@/components/ui/markdown-editor';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { CommentItem } from '../types';
import { commentAnchorId } from './comment-anchor';
import { timeAgo } from './format';
import { SectionHead } from './section-head';

type CommentsSectionProps = {
    issueId: string;
    comments: CommentItem[];
};

/**
 * Maquette issue-comments: markdown bodies, author-only actions revealed on
 * hover, and a comment box (avatar + textarea + Preview / Comment).
 */
export function CommentsSection({ issueId, comments }: CommentsSectionProps) {
    const { auth } = usePage<{ auth: { user: { id: number; name: string } | null } }>().props;
    const { t } = useTranslation();
    const [editingComment, setEditingComment] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);

    const commentForm = useForm({ body: '' });
    const commentEditForm = useForm({ body: '' });

    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();
        commentForm.post(route('comments.store', issueId), {
            onSuccess: () => {
                commentForm.reset();
                setPreviewing(false);
            },
        });
    };

    const submitCommentEdit = (e: React.FormEvent, commentId: string) => {
        e.preventDefault();
        commentEditForm.patch(route('comments.update', [issueId, commentId]), {
            onSuccess: () => setEditingComment(null),
        });
    };

    const removeComment = (commentId: string) => {
        if (!window.confirm(t('comments.deleteConfirm'))) return;
        router.delete(route('comments.destroy', [issueId, commentId]));
    };

    return (
        <section>
            <SectionHead title={t('comments.title', { count: comments.length })} />
            <ul className="divide-y">
                {comments.map((comment) => (
                    <li
                        key={comment.id}
                        id={commentAnchorId(comment.id)}
                        className="group flex scroll-mt-24 gap-3 rounded-md py-3.5 transition-colors duration-700 [&.comment-flash]:bg-primary/10"
                    >
                        <Avatar name={comment.authorName} />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-[13px] font-medium">{comment.authorName}</span>
                                <span className="text-xs text-muted-foreground">
                                    {comment.createdAt ? timeAgo(comment.createdAt) : ''}
                                </span>
                            </div>
                            {editingComment === comment.id ? (
                                <form onSubmit={(e) => submitCommentEdit(e, comment.id)} className="mt-2 grid gap-2">
                                    <MentionAutocomplete
                                        data-test="comment-edit-body"
                                        value={commentEditForm.data.body}
                                        onChange={(body) => commentEditForm.setData('body', body)}
                                        required
                                        maxLength={20000}
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            data-test="comment-edit-save"
                                            disabled={commentEditForm.processing}
                                        >
                                            {t('common.save')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditingComment(null)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <MarkdownView value={comment.body} className="mt-0.5 text-[13.5px]" />
                            )}
                        </div>
                        {auth.user && auth.user.id === comment.authorId ? (
                            <span className="flex shrink-0 gap-0.5 self-start opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100 max-lg:opacity-100">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('comments.editBy', { name: comment.authorName })}
                                    data-test="comment-edit"
                                    onClick={() => {
                                        setEditingComment(comment.id);
                                        commentEditForm.setData('body', comment.body);
                                    }}
                                >
                                    <Pencil aria-hidden />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('comments.deleteBy', { name: comment.authorName })}
                                    data-test="comment-delete"
                                    onClick={() => removeComment(comment.id)}
                                >
                                    <Trash2 aria-hidden />
                                </Button>
                            </span>
                        ) : null}
                    </li>
                ))}
            </ul>

            <div className="mt-3.5 flex gap-3">
                <Avatar name={auth.user?.name ?? '?'} />
                <form onSubmit={submitComment} className="grid min-w-0 flex-1 gap-2">
                    <span className="font-mono text-xs text-muted-foreground">{t('comments.addLabel')}</span>
                    {previewing ? (
                        <div
                            data-test="comment-preview-view"
                            className="min-h-[70px] rounded-lg border bg-card px-3.5 py-2.5"
                        >
                            {commentForm.data.body ? (
                                <MarkdownView value={commentForm.data.body} className="text-[13.5px]" />
                            ) : (
                                <p className="text-sm italic text-muted-foreground">{t('issueShow.nothingToPreview')}</p>
                            )}
                        </div>
                    ) : (
                        <MentionAutocomplete
                            data-test="comment-body"
                            placeholder={t('comments.placeholder')}
                            className="min-h-[70px]"
                            value={commentForm.data.body}
                            onChange={(body) => commentForm.setData('body', body)}
                            required
                            maxLength={20000}
                            aria-invalid={commentForm.errors.body ? true : undefined}
                        />
                    )}
                    {commentForm.errors.body ? (
                        <p className="text-sm text-destructive">{commentForm.errors.body}</p>
                    ) : null}
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="comment-preview"
                            onClick={() => setPreviewing((value) => !value)}
                        >
                            {previewing ? t('issueShow.write') : t('issueShow.preview')}
                        </Button>
                        <Button type="submit" size="sm" data-test="comment-submit" disabled={commentForm.processing}>
                            {t('comments.submit')}
                        </Button>
                    </div>
                </form>
            </div>
        </section>
    );
}
