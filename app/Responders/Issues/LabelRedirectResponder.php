<?php

namespace App\Responders\Issues;

use App\Models\Label;
use Illuminate\Http\RedirectResponse;

class LabelRedirectResponder
{
    public function created(Label $label): RedirectResponse
    {
        return redirect()
            ->route('issues.index')
            ->with('status', "Label \"{$label->name}\" created.");
    }
}
