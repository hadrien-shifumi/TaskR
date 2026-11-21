<?php

namespace App\Chat\Tools;

use App\Actions\Projects\ListProjects as ListProjectsAction;
use App\Models\User;
use App\Models\Workspace;

/**
 * List the projects of the workspace (id, name, description, dates) —
 * the entry point the model needs before list_issues, get_issue or a
 * project-scoped knowledge search. The scope follows the requesting
 * user exactly as the screens show it.
 */
class ListProjects extends ChatTool
{
    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'List the projects of a workspace (id, name, description, target date, archived date). Each member only sees the projects of their teams; the workspace owner sees them all.';
    }

    public function schema(): array
    {
        return [];
    }

    protected function rules(Workspace $workspace): array
    {
        return [];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        return [
            'projects' => ListProjectsAction::run($workspace, $user),
        ];
    }
}
