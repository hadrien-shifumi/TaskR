import { Avatar } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { MarkdownView } from '@/components/ui/markdown-editor';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { CommentItem } from '../types';
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
        if (!window.confirm('Delete this comment?')) return;
        router.delete(route('comments.destroy', [issueId, commentId]));
    };

    return (
        <section>
            <SectionHead title={`Comments (${comments.length})`} />
            <ul className="divide-y">
                {comments.map((comment) => (
                    <li key={comment.id} className="group flex gap-3 py-3.5">
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
                                    <Textarea
                                        data-test="comment-edit-body"
                                        value={commentEditForm.data.body}
                                        onChange={(e) => commentEditForm.setData('body', e.target.value)}
                                        required
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            data-test="comment-edit-save"
                                            disabled={commentEditForm.processing}
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditingComment(null)}
                                        >
                                            Cancel
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
                                    aria-label={`Edit comment by ${comment.authorName}`}
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
                                    aria-label={`Delete comment by ${comment.authorName}`}
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
                    <span className="font-mono text-xs text-muted-foreground">Add a comment (Markdown)</span>
                    {previewing ? (
                        <div
                            data-test="comment-preview-view"
                            className="min-h-[70px] rounded-lg border bg-card px-3.5 py-2.5"
                        >
                            {commentForm.data.body ? (
                                <MarkdownView value={commentForm.data.body} className="text-[13.5px]" />
                            ) : (
                                <p className="text-sm italic text-muted-foreground">Nothing to preview yet.</p>
                            )}
                        </div>
                    ) : (
                        <Textarea
                            data-test="comment-body"
                            placeholder="Add a comment..."
                            className="min-h-[70px]"
                            value={commentForm.data.body}
                            onChange={(e) => commentForm.setData('body', e.target.value)}
                            required
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
                            {previewing ? 'Write' : 'Preview'}
                        </Button>
                        <Button type="submit" size="sm" data-test="comment-submit" disabled={commentForm.processing}>
                            Comment
                        </Button>
                    </div>
                </form>
            </div>
        </section>
    );
}
