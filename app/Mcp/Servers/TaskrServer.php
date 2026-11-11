<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectIssues;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\ArchiveProject;
use App\Mcp\Tools\CompareKnowledgeVersions;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\CreateKnowledge;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\CreateStatus;
use App\Mcp\Tools\CreateTemplate;
use App\Mcp\Tools\CreateWorkspace;
use App\Mcp\Tools\DeleteComment;
use App\Mcp\Tools\DeleteIssue;
use App\Mcp\Tools\DeleteKnowledge;
use App\Mcp\Tools\DeleteProject;
use App\Mcp\Tools\DeleteStatus;
use App\Mcp\Tools\DeleteTemplate;
use App\Mcp\Tools\DeleteTimeEntry;
use App\Mcp\Tools\ForceDeleteKnowledge;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\GetKnowledge;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\InviteMember;
use App\Mcp\Tools\ListActivity;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListKnowledge;
use App\Mcp\Tools\ListKnowledgeTrash;
use App\Mcp\Tools\ListKnowledgeVersions;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListMembers;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListSavedViewIssues;
use App\Mcp\Tools\ListSavedViews;
use App\Mcp\Tools\ListStatuses;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\ListWorkspaces;
use App\Mcp\Tools\LogTime;
use App\Mcp\Tools\MoveIssue;
use App\Mcp\Tools\RemoveMember;
use App\Mcp\Tools\ReorderStatuses;
use App\Mcp\Tools\RestoreKnowledge;
use App\Mcp\Tools\RestoreKnowledgeVersion;
use App\Mcp\Tools\SearchKnowledge;
use App\Mcp\Tools\SetAssignees;
use App\Mcp\Tools\SetIssueDependencies;
use App\Mcp\Tools\SetIssueEstimate;
use App\Mcp\Tools\SetIssueKnowledge;
use App\Mcp\Tools\SetIssueParent;
use App\Mcp\Tools\SetLabels;
use App\Mcp\Tools\UpdateComment;
use App\Mcp\Tools\UpdateIssue;
use App\Mcp\Tools\UpdateKnowledge;
use App\Mcp\Tools\UpdateProject;
use App\Mcp\Tools\UpdateStatus;
use App\Mcp\Tools\UpdateTemplate;
use App\Mcp\Tools\UpdateTimeEntry;
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
    Tools your roles can never use are simply absent from this catalog: if a
    tool you need is missing, it is reserved to higher workspace roles.
    MARKDOWN)]
class TaskrServer extends Server
{
    /**
     * One tools/list page must carry the whole catalog. The package defaults
     * (15 per page, 50 max) truncate the listing after update_issue, so any
     * agent reading a single page — the common case — never discovers
     * log_time, update_time_entry or delete_time_entry and cannot modify a
     * time entry through MCP. 100 covers the current 57 tools in one response.
     */
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        // Workspaces & members
        ListWorkspaces::class,
        CreateWorkspace::class,
        InviteMember::class,
        RemoveMember::class,
        ListMembers::class,
        // Projects
        ListProjects::class,
        CreateProject::class,
        UpdateProject::class,
        ArchiveProject::class,
        DeleteProject::class,
        GetProjectHealth::class,
        // Issues
        ListIssues::class,
        GetIssue::class,
        CreateIssue::class,
        UpdateIssue::class,
        DeleteIssue::class,
        MoveIssue::class,
        SetIssueParent::class,
        SetIssueDependencies::class,
        SetAssignees::class,
        SetLabels::class,
        SetIssueEstimate::class,
        SetIssueKnowledge::class,
        // Comments
        AddComment::class,
        ListComments::class,
        UpdateComment::class,
        DeleteComment::class,
        // Statuses & labels
        ListStatuses::class,
        CreateStatus::class,
        UpdateStatus::class,
        DeleteStatus::class,
        ReorderStatuses::class,
        ListLabels::class,
        CreateLabel::class,
        // Time tracking
        LogTime::class,
        ListTimeEntries::class,
        UpdateTimeEntry::class,
        DeleteTimeEntry::class,
        // Knowledge
        ListKnowledge::class,
        GetKnowledge::class,
        SearchKnowledge::class,
        CreateKnowledge::class,
        UpdateKnowledge::class,
        DeleteKnowledge::class,
        ListKnowledgeTrash::class,
        RestoreKnowledge::class,
        ForceDeleteKnowledge::class,
        ListKnowledgeVersions::class,
        RestoreKnowledgeVersion::class,
        CompareKnowledgeVersions::class,
        // Templates
        ListTemplates::class,
        CreateTemplate::class,
        UpdateTemplate::class,
        DeleteTemplate::class,
        // Saved views
        ListSavedViews::class,
        ListSavedViewIssues::class,
        // Audit
        ListActivity::class,
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
