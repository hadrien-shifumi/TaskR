<?php

namespace App\Actions\Workspaces;

use App\Models\Workspace;
use App\Responders\Workspaces\GeneralSettingsPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowGeneralSettingsPage
{
    use AsController;
    use AsObject;

    /**
     * Present the workspace's general settings: the current value of the
     * "parents as containers" flag, the only toggle of the section.
     *
     * @return array{parentsAsContainers: bool}
     */
    public function handle(Workspace $workspace): array
    {
        return [
            'parentsAsContainers' => (bool) $workspace->parents_as_containers,
        ];
    }

    public function asController(Request $request, GeneralSettingsPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}
