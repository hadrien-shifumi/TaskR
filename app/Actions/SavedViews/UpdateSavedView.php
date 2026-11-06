<?php

namespace App\Actions\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\SavedViews\SavedViewRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateSavedView
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    public function rules(): array
    {
        $view = request()->route('view');
        $workspaceId = $this->currentWorkspace()?->id;

        $unique = Rule::unique('saved_views', 'name')
            ->where('workspace_id', $workspaceId);

        if ($view instanceof SavedView) {
            $unique = $unique->ignore($view->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
        ];
    }

    /**
     * Rename a saved view. The rest of the definition — filters, sort and
     * frozen summary — is untouched: managing a view never re-captures it.
     *
     * @param  array{name: string}  $data
     */
    public function handle(SavedView $view, array $data): SavedView
    {
        $view->update(['name' => $data['name']]);

        return $view->refresh();
    }

    public function asController(ActionRequest $request, SavedView $view, SavedViewRedirectResponder $responder): RedirectResponse
    {
        $view = $this->handle($view, $request->validated());

        return $responder->updated($view);
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
