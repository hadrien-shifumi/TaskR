<?php

namespace App\Responders\Comments;

use App\Models\Comment;
use Illuminate\Http\RedirectResponse;

class CommentRedirectResponder
{
    public function added(Comment $comment): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $comment->issue_id)
            ->with('status', __('flash.comment.added'));
    }

    public function updated(Comment $comment): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $comment->issue_id)
            ->with('status', __('flash.comment.updated'));
    }

    public function deleted(Comment $comment): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $comment->issue_id)
            ->with('status', __('flash.comment.deleted'));
    }
}
