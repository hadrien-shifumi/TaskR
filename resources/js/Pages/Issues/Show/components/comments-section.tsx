import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
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
        <div>
            <h2 className="mb-2 text-lg font-semibold">Comments ({comments.length})</h2>
            <ul className="space-y-3">
                {comments.map((comment) => (
                    <li key={comment.id} className="rounded-md border px-4 py-3">
                        <div className="mb-1 flex items-center gap-2 text-sm">
                            <span className="font-medium">{comment.authorName}</span>
                            <span className="text-muted-foreground">
                                {comment.createdAt
                                    ? new Date(comment.createdAt).toLocaleString()
                                    : ''}
                            </span>
                            {auth.user && auth.user.id === comment.authorId ? (
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        data-test="comment-edit"
                                        onClick={() => {
                                            setEditingComment(comment.id);
                                            commentEditForm.setData('body', comment.body);
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        data-test="comment-delete"
                                        onClick={() => removeComment(comment.id)}
                                    >
                                        Delete
                                    </Button>
                                </span>
                            ) : null}
                        </div>
                        {editingComment === comment.id ? (
                            <form onSubmit={(e) => submitCommentEdit(e, comment.id)} className="grid gap-2">
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
                            <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                {comment.body}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
            <form onSubmit={submitComment} className="mt-4 grid gap-2">
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
        </div>
    );
}
