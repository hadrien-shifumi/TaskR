<?php

namespace App\Actions\Workspaces;

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\InviteMemberResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class InviteMember
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = request()->route('workspace');

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @param  array{email: string}  $data
     */
    public function handle(Workspace $workspace, array $data): Invitation
    {
        return Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $data['email'],
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function asController(ActionRequest $request, Workspace $workspace, InviteMemberResponder $responder): RedirectResponse
    {
        $invitation = $this->handle($workspace, $request->validated());

        return $responder->toResponse($request, $invitation);
    }
}
