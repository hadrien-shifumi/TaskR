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
}
