<?php

namespace App\Responders\Mentions;

use App\Models\CommentMention;
use Illuminate\Http\RedirectResponse;

class MentionRedirectResponder
{
    /**
     * Reading a mention lands on the issue it was written on.
     */
    public function read(CommentMention $mention): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $mention->comment->issue_id)
            ->with('status', __('flash.mention.read'));
    }

    public function readAll(): RedirectResponse
    {
        return redirect()
            ->route('mentions.index')
            ->with('status', __('flash.mention.read_all'));
    }
}
