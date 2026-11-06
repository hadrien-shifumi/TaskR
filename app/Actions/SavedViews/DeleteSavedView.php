<?php

namespace App\Actions\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\SavedViews\SavedViewRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteSavedView
{
    use AsController;
    use AsObject;

    /**
     * The Owner manages every view of the workspace; anyone else may only
     * remove a view they created themselves (belonging is permission —
     * whoever can create a view can withdraw their own, guests included).
     * A view whose creator is gone (account deleted) falls back to the
     * Owner alone.
     */
    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();
        $view = request()->route('view');

        return $user instanceof User
            && $workspace !== null
            && $view instanceof SavedView
            && ($workspace->hasOwner($user) || $view->created_by === $user->getKey());
    }

    public function handle(SavedView $view): SavedView
    {
        $view->delete();

        return $view;
    }

    public function asController(SavedView $view, SavedViewRedirectResponder $responder): RedirectResponse
    {
        $view = $this->handle($view);

        return $responder->deleted($view);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
