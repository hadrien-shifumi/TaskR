<?php

namespace App\Responders\SavedViews;

use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;

class SavedViewRedirectResponder
{
    /**
     * Land back on the issues page, on the exact list the view holds: the
     * stored definition round-trips into the URL it was saved from.
     */
    public function created(SavedView $view): RedirectResponse
    {
        $params = array_filter($view->filters ?? [], fn ($value) => $value !== null && $value !== '');

        if ($view->sort !== null) {
            $params['sort'] = $view->sort;
        }

        if ($view->dir !== null) {
            $params['dir'] = $view->dir;
        }

        return redirect()
            ->route('issues.index', $params)
            ->with('status', __('flash.saved_view.created', ['name' => $view->name]));
    }

    /**
     * After a rename, back on the issues page; the flash carries the new
     * name.
     */
    public function updated(SavedView $view): RedirectResponse
    {
        return redirect()
            ->route('issues.index')
            ->with('status', __('flash.saved_view.updated', ['name' => $view->name]));
    }

    /**
     * After a deletion, back on the issues page — the view is gone for
     * every member of the workspace.
     */
    public function deleted(SavedView $view): RedirectResponse
    {
        return redirect()
            ->route('issues.index')
            ->with('status', __('flash.saved_view.deleted', ['name' => $view->name]));
    }
}
