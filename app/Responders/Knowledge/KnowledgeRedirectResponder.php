<?php

namespace App\Responders\Knowledge;

use App\Models\KnowledgePage;
use Illuminate\Http\RedirectResponse;

class KnowledgeRedirectResponder
{
    public function created(KnowledgePage $page): RedirectResponse
    {
        return redirect()
            ->route('knowledge.show', $page)
            ->with('status', __('flash.page.created', ['title' => $page->title]));
    }

    public function updated(KnowledgePage $page): RedirectResponse
    {
        return redirect()
            ->route('knowledge.show', $page)
            ->with('status', __('flash.page.updated', ['title' => $page->title]));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('knowledge.index')
            ->with('status', __('flash.page.deleted'));
    }
}
