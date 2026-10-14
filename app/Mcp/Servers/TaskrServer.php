<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectIssues;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\ArchiveProject;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\DeleteIssue;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListStatuses;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\ListWorkspaces;
use App\Mcp\Tools\LogTime;
use App\Mcp\Tools\MoveIssue;
use App\Mcp\Tools\SetAssignees;
use App\Mcp\Tools\SetIssueParent;
use App\Mcp\Tools\SetLabels;
use App\Mcp\Tools\UpdateIssue;
use App\Mcp\Tools\UpdateProject;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Taskr')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Taskr issue tracker. Call list_workspaces first to discover the workspace
    identifiers, then pass the target `workspace_id` to every other tool. Every
    tool enforces the same validation and permissions as the web UI: validation
    failures come back with the offending fields, refusals with a clear message.
    MARKDOWN)]
class TaskrServer extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ListWorkspaces::class,
        ListProjects::class,
        CreateProject::class,
        UpdateProject::class,
        ArchiveProject::class,
        ListIssues::class,
        CreateIssue::class,
        UpdateIssue::class,
        DeleteIssue::class,
        MoveIssue::class,
        SetIssueParent::class,
        SetAssignees::class,
        SetLabels::class,
        AddComment::class,
        ListComments::class,
        ListStatuses::class,
        ListLabels::class,
        LogTime::class,
        ListTimeEntries::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected array $resources = [
        ProjectIssues::class,
    ];

    /**
     * @var array<string, array<string, bool>|string>
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
        self::CAPABILITY_RESOURCES => [
            'listChanged' => false,
        ],
    ];
}
