<?php

namespace App\Responders\Comments;

use App\Models\Comment;
use Illuminate\Http\RedirectResponse;

class CommentRedirectResponder
{
    /**
     * Comment mutations come from the full issue page or from the board's
     * issue modal (?issue= param): stay in the caller's context so the
     * modal never drops the user back to the bare issue page.
     */
    private function back(string $status): RedirectResponse
    {
        return redirect()->back()->with('status', $status);
    }

    public function added(Comment $comment): RedirectResponse
    {
        return $this->back(__('flash.comment.added'));
    }

    public function updated(Comment $comment): RedirectResponse
    {
        return $this->back(__('flash.comment.updated'));
    }

    public function deleted(Comment $comment): RedirectResponse
    {
        return $this->back(__('flash.comment.deleted'));
    }
}
