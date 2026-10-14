<?php

namespace App\Mcp\Resources;

use App\Actions\Issues\ListIssues;
use App\Actions\Workspaces\ResolveAgentWorkspace;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[MimeType('application/json')]
#[Description('The issues of a Taskr project, newest first.')]
class ProjectIssues extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('taskr://workspaces/{workspace_id}/projects/{project_id}/issues');
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                throw new AuthenticationException('Unauthenticated.');
            }

            $workspace = ResolveAgentWorkspace::run($user, (string) $request->get('workspace_id'));

            $project = Project::query()->findOrFail($request->get('project_id'));

            $result = ListIssues::run($workspace, ['project' => $project->id]);

            return Response::structured(['issues' => $result['issues']]);
        } catch (AuthorizationException $exception) {
            return Response::error($exception->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('The requested resource was not found in this workspace.');
        } finally {
            app(WorkspaceContext::class)->set(null);
        }
    }
}
