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
            ->with('status', "Page \"{$page->title}\" created.");
    }

    public function updated(KnowledgePage $page): RedirectResponse
    {
        return redirect()
            ->route('knowledge.show', $page)
            ->with('status', "Page \"{$page->title}\" updated.");
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('knowledge.index')
            ->with('status', 'Page deleted.');
    }
}
