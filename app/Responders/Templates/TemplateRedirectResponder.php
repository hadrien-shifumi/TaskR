<?php

namespace App\Responders\Templates;

use App\Models\IssueTemplate;
use Illuminate\Http\RedirectResponse;

class TemplateRedirectResponder
{
    public function created(IssueTemplate $template): RedirectResponse
    {
        return redirect()
            ->route('settings.templates')
            ->with('status', "Template \"{$template->name}\" created.");
    }

    public function updated(?IssueTemplate $template): RedirectResponse
    {
        return redirect()
            ->route('settings.templates')
            ->with('status', 'Template updated.');
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('settings.templates')
            ->with('status', 'Template deleted.');
    }
}
