<?php

namespace App\Responders\Knowledge;

use Illuminate\Http\RedirectResponse;

class EmbeddingsSettingsRedirectResponder
{
    public function updated(): RedirectResponse
    {
        return redirect()
            ->route('settings.embeddings')
            ->with('status', __('flash.embeddings.updated'));
    }

    public function reembed(int $count): RedirectResponse
    {
        return redirect()
            ->route('settings.embeddings')
            ->with('status', __('flash.embeddings.reembed', ['count' => $count]));
    }
}
