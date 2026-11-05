<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\GeneralSettingsRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

class UpdateGeneralSettings
{
    use AsController;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = app(WorkspaceContext::class)->get();

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            'parents_as_containers' => ['required', 'boolean'],
        ];
    }

    /**
     * Persist the general settings of the workspace, then land back on the
     * general settings page with a confirmation flash.
     */
    public function asController(ActionRequest $request, GeneralSettingsRedirectResponder $responder): RedirectResponse
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();
        $workspace->update([
            'parents_as_containers' => filter_var(
                (string) $request->validated('parents_as_containers'),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ]);

        return $responder->updated();
    }
}
