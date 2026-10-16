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
            ->with('status', __('flash.template.created', ['name' => $template->name]));
    }

    public function updated(?IssueTemplate $template): RedirectResponse
    {
        return redirect()
            ->route('settings.templates')
            ->with('status', __('flash.template.updated'));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('settings.templates')
            ->with('status', __('flash.template.deleted'));
    }
}
