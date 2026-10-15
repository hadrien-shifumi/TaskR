import { Avatar } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { CommentItem } from '../types';

type CommentsSectionProps = {
    issueId: string;
    comments: CommentItem[];
};

/** Comment thread with author-scoped inline editing and the add form. */
export function CommentsSection({ issueId, comments }: CommentsSectionProps) {
    const { auth } = usePage<{ auth: { user: { id: number } | null } }>().props;
    const [editingComment, setEditingComment] = useState<string | null>(null);

    const commentForm = useForm({ body: '' });
    const commentEditForm = useForm({ body: '' });

    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();
        commentForm.post(route('comments.store', issueId), {
            onSuccess: () => commentForm.reset(),
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
            <h2 className="mb-2 text-base font-semibold">Comments ({comments.length})</h2>
            <ul className="divide-y">
                {comments.map((comment) => (
                    <li key={comment.id} className="group flex gap-3 py-3.5">
                        <Avatar name={comment.authorName} />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-[13px] font-medium">{comment.authorName}</span>
                                <span className="text-xs text-muted-foreground">
                                    {comment.createdAt ? new Date(comment.createdAt).toLocaleString() : ''}
                                </span>
                                {auth.user && auth.user.id === comment.authorId ? (
                                    <span className="ml-auto flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100 max-lg:opacity-100">
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
                                        <Button type="submit" size="sm" data-test="comment-edit-save" disabled={commentEditForm.processing}>
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
                                <p className="mt-0.5 text-[13.5px] whitespace-pre-wrap">{comment.body}</p>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <form onSubmit={submitComment} className="mt-4 grid gap-2 rounded-lg border bg-card p-4">
                <Textarea
                    placeholder="Add a comment..."
                    value={commentForm.data.body}
                    onChange={(e) => commentForm.setData('body', e.target.value)}
                    required
                />
                {commentForm.errors.body ? (
                    <p className="text-sm text-destructive">{commentForm.errors.body}</p>
                ) : null}
                <Button type="submit" size="sm" data-test="comment-submit" disabled={commentForm.processing} className="justify-self-start">
                    Comment
                </Button>
            </form>
        </section>
    );
}
