<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\LoadActiveDescendants;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\AddProjectMember;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Templates\CreateIssueTemplate;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Auth\TokenPresenter;
use App\Presenters\Comments\CommentPresenter;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Presenters\Templates\TemplatePresenter;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Presenters\Workspaces\MemberPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PresentersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private function setupWorkspace(): void
    {
        $this->owner = User::factory()->create();
        $this->workspace = CreateWorkspace::run($this->owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($this->workspace);
        $this->project = CreateProject::run($this->workspace, $this->owner, ['name' => 'Site vitrine']);
    }

    private function addMember(): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $this->workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    // ── IssuePresenter ──────────────────────────────────────────────

    public function test_issue_card_shape(): void
    {
        $this->setupWorkspace();
        $member = $this->addMember();
        $label = CreateLabel::run($this->workspace, ['name' => 'front', 'color' => 'red']);
        $status = $this->workspace->statuses()->where('category', 'in_progress')->first();

        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'UI formulaire',
            'status_id' => $status->id,
            'priority' => 'high',
            'due_date' => now()->addDay()->toDateString(),
            'assignees' => [$member->id],
            'label_ids' => [$label->id],
        ]);

        $card = IssuePresenter::card($issue);

        $this->assertSame($issue->id, $card['id']);
        $this->assertSame('UI formulaire', $card['title']);
        $this->assertSame(['id' => $this->project->id, 'name' => 'Site vitrine'], $card['project']);
        $this->assertSame($status->id, $card['status']['id']);
        $this->assertSame($status->name, $card['status']['name']);
        $this->assertSame('in_progress', $card['status']['category']);
        $this->assertSame('high', $card['priority']);
        $this->assertSame($issue->due_date->toISOString(), $card['dueDate']);
        $this->assertFalse($card['overdue']);
        $this->assertFalse($card['blocked']);
        $this->assertFalse($card['unblockable']);
        $this->assertSame([['id' => $member->id, 'name' => $member->name]], $card['assignees']);
        $this->assertSame([['id' => $label->id, 'name' => 'front', 'color' => 'red']], $card['labels']);
    }

    public function test_issue_kanban_card_strips_project_and_status(): void
    {
        $this->setupWorkspace();
        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Carte',
        ]);

        $kanban = IssuePresenter::kanbanCard(IssuePresenter::card($issue));

        $this->assertSame(
            ['id', 'title', 'priority', 'dueDate', 'overdue', 'blocked', 'unblockable', 'hasOpenChild', 'descendants', 'assignees', 'labels', 'knowledgePagesCount', 'knowledgePageTitles'],
            array_keys($kanban),
        );
        $this->assertSame($issue->id, $kanban['id']);
        $this->assertSame('none', $kanban['priority']);
    }

    public function test_issue_board_card_uses_date_string_due_date(): void
    {
        $this->setupWorkspace();
        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Colonne',
            'due_date' => '2026-12-01',
        ]);

        $board = IssuePresenter::boardCard($issue->load('assignees'));

        $this->assertSame(
            ['id', 'title', 'priority', 'dueDate', 'overdue', 'blocked', 'unblockable', 'hasOpenChild', 'descendants', 'assignees', 'knowledgePagesCount', 'knowledgePageTitles'],
            array_keys($board),
        );
        $this->assertSame('2026-12-01', $board['dueDate']);
        $this->assertFalse($board['overdue']);
    }

    public function test_issue_home_card_flattens_project_and_status(): void
    {
        $this->setupWorkspace();
        $status = $this->workspace->statuses()->where('category', 'in_progress')->first();
        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Accueil',
            'status_id' => $status->id,
            'due_date' => '2026-11-15',
        ]);

        $home = IssuePresenter::homeCard($issue->load(['project', 'status']));

        $this->assertSame(
            ['id', 'title', 'projectName', 'statusName', 'statusCategory', 'priority', 'dueDate', 'overdue'],
            array_keys($home),
        );
        $this->assertSame('Site vitrine', $home['projectName']);
        $this->assertSame($status->name, $home['statusName']);
        $this->assertSame('in_progress', $home['statusCategory']);
        $this->assertSame('2026-11-15', $home['dueDate']);
    }

    public function test_issue_detail_exposes_hours_and_hierarchy(): void
    {
        $this->setupWorkspace();
        $parent = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Parent',
        ]);
        $child = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Child',
            'parent_id' => $parent->id,
        ]);
        SetIssueEstimate::run($child, 6.0);
        CreateTimeEntry::run($child, $this->owner, ['hours' => 1.5]);

        $child->load(['project', 'status', 'labels', 'assignees', 'creator']);
        $parent->load('status');

        $detail = IssuePresenter::detail($child, $parent, $child->children()->with(['status', 'assignees'])->get());

        $this->assertSame($child->id, $detail['id']);
        $this->assertSame(6.0, $detail['estimatedHours']);
        $this->assertSame(1.5, $detail['loggedHours']);
        $this->assertSame(4.5, $detail['remainingHours']);
        $this->assertSame($child->creator?->name, $detail['creatorName']);
        $this->assertSame(
            ['id' => $parent->id, 'title' => 'Parent', 'statusName' => $parent->status?->name],
            $detail['parent'],
        );

        $parentSummary = IssuePresenter::parentSummary($parent);
        $this->assertSame(['id', 'title', 'statusName'], array_keys($parentSummary));

        $childSummary = IssuePresenter::childSummary($child);
        $this->assertSame(['id', 'title', 'statusId', 'statusName', 'statusCategory', 'assignees'], array_keys($childSummary));
    }

    public function test_issue_late_issue_shape(): void
    {
        $this->setupWorkspace();
        CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'En retard',
            'priority' => 'urgent',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $late = $this->project->issues()
            ->join('statuses', 'statuses.id', '=', 'issues.status_id')
            ->whereNotNull('issues.due_date')
            ->where('issues.due_date', '<', now()->toDateString())
            ->orderBy('issues.due_date')
            ->get(['issues.id', 'issues.title', 'issues.priority', 'issues.due_date', 'statuses.name as statusName', 'statuses.category as statusCategory'])
            ->first();

        $presented = IssuePresenter::lateIssue($late);

        $this->assertSame(
            ['id', 'title', 'priority', 'dueDate', 'statusName', 'statusCategory'],
            array_keys($presented),
        );
        $this->assertSame('urgent', $presented['priority']);
        $this->assertSame(now()->subDay()->toDateString(), $presented['dueDate']);
        $this->assertSame('backlog', $presented['statusCategory']);
    }

    // ── CommentPresenter / TimeEntryPresenter ───────────────────────

    public function test_comment_presenters_null_vs_unknown_author(): void
    {
        $this->setupWorkspace();
        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Commenté',
        ]);
        $comment = AddComment::run($issue, $this->owner, 'Premier retour');

        $this->assertSame($this->owner->name, CommentPresenter::present($comment)['authorName']);

        $comment->setRelation('author', null);

        $this->assertNull(CommentPresenter::present($comment)['authorName']);
        $this->assertSame('Unknown', CommentPresenter::detail($comment)['authorName']);
    }

    public function test_time_entry_presenters_date_vs_iso(): void
    {
        $this->setupWorkspace();
        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Chrono',
        ]);
        $entry = CreateTimeEntry::run($issue, $this->owner, [
            'hours' => 1.5,
            'worked_at' => '2026-10-05',
            'note' => 'Dev',
        ]);

        $listed = TimeEntryPresenter::present($entry->load('author'));
        $this->assertSame('2026-10-05', $listed['workedAt']);
        $this->assertSame(1.5, $listed['hours']);
        $this->assertSame('Dev', $listed['note']);

        $detail = TimeEntryPresenter::detail($entry->load('author'));
        $this->assertSame($entry->worked_at->toISOString(), $detail['workedAt']);
    }

    // ── ProjectPresenter / ProjectMemberPresenter ───────────────────

    public function test_project_presenter_variants(): void
    {
        $this->setupWorkspace();
        $project = CreateProject::run($this->workspace, $this->owner, [
            'name' => 'Refonte',
            'description' => 'Nouveau site',
            'target_date' => '2026-12-24',
        ]);
        $health = GetProjectHealth::run($project);

        $summary = ProjectPresenter::summary($project);
        $this->assertSame($project->id, $summary['id']);
        $this->assertSame('Refonte', $summary['name']);
        $this->assertSame('Nouveau site', $summary['description']);
        $this->assertSame('2026-12-24', $summary['targetDate']);
        $this->assertSame($project->created_at->toISOString(), $summary['createdAt']);

        $listItem = ProjectPresenter::listItem($project);
        $this->assertSame('2026-12-24T00:00:00.000000Z', $listItem['targetDate']);
        $this->assertNull($listItem['archivedAt']);

        $pageCard = ProjectPresenter::pageCard($project, $health);
        $this->assertSame($health->toArray(), $pageCard['health']);
        $this->assertArrayHasKey('archivedAt', $pageCard);

        $homeCard = ProjectPresenter::homeCard($project, $health);
        $this->assertSame(['id', 'name', 'health'], array_keys($homeCard));

        $this->assertSame(['id' => $project->id, 'name' => 'Refonte'], ProjectPresenter::option($project));
    }

    public function test_project_member_presenter_roster_and_person(): void
    {
        $this->setupWorkspace();
        $member = $this->addMember();
        $projectMember = AddProjectMember::run($this->project, ['user_id' => $member->id]);

        $roster = ProjectMemberPresenter::rosterMember($projectMember->load('user'));
        $this->assertSame($member->id, $roster['id']);
        $this->assertSame($member->name, $roster['name']);
        $this->assertSame($member->email, $roster['email']);
        $this->assertSame($projectMember->role->value, $roster['role']);
        $this->assertSame($projectMember->created_at->toISOString(), $roster['joinedAt']);

        $this->assertSame(
            ['id' => $member->id, 'name' => $member->name],
            ProjectMemberPresenter::person($member),
        );
        $this->assertSame(
            ['id' => 7, 'name' => 'Ada'],
            ProjectMemberPresenter::person((object) ['id' => 7, 'name' => 'Ada']),
        );
        $this->assertSame(
            ['id' => $member->id, 'name' => $member->name, 'email' => $member->email],
            ProjectMemberPresenter::contact($member),
        );
    }

    // ── MemberPresenter (workspaces) ────────────────────────────────

    public function test_invitation_presenter_pending_and_expired(): void
    {
        $this->setupWorkspace();
        $invitation = InviteMember::run($this->workspace, [
            'email' => 'client@example.fr',
            'role' => 'guest',
            'project_ids' => [$this->project->id],
        ]);

        $presented = MemberPresenter::invitation($invitation->load('projects'));
        $this->assertSame($invitation->id, $presented['id']);
        $this->assertSame('client@example.fr', $presented['email']);
        $this->assertSame('guest', $presented['role']);
        $this->assertSame(['Site vitrine'], $presented['projects']);
        $this->assertSame('sent', $presented['status']);
        $this->assertSame($invitation->created_at->toISOString(), $presented['invitedAt']);

        $invitation->update(['expires_at' => now()->subDay()]);
        $this->assertSame('expired', MemberPresenter::invitation($invitation->fresh()->load('projects'))['status']);
    }

    // ── TemplatePresenter / StatusPresenter ─────────────────────────

    public function test_template_presenter_with_labels(): void
    {
        $this->setupWorkspace();
        $label = CreateLabel::run($this->workspace, ['name' => 'bug']);
        $template = CreateIssueTemplate::run($this->workspace, [
            'name' => 'Rapport de bug',
            'description' => 'Étapes, attendu, observé',
            'priority' => 'high',
            'label_ids' => [$label->id],
        ]);

        $presented = TemplatePresenter::present($template->load('labels'));

        $this->assertSame($template->id, $presented['id']);
        $this->assertSame('Rapport de bug', $presented['name']);
        $this->assertSame('Étapes, attendu, observé', $presented['description']);
        $this->assertSame('high', $presented['priority']);
        $this->assertSame([['id' => $label->id, 'name' => 'bug']], $presented['labels']);
    }

    public function test_status_presenter_present_option_managed_list(): void
    {
        $this->setupWorkspace();
        CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Ticket',
        ]);
        $status = $this->workspace->statuses()
            ->withCount('issues')
            ->with(['issues:id,status_id'])
            ->first();

        $presented = StatusPresenter::present($status);
        $this->assertSame(
            ['id', 'name', 'category', 'position'],
            array_keys($presented),
        );
        $this->assertSame($status->category->value, $presented['category']);

        $option = StatusPresenter::option($status);
        $this->assertSame(['id', 'name', 'category'], array_keys($option));

        $managed = StatusPresenter::managedList($status);
        $this->assertSame($status->issues_count, $managed['issuesCount']);
        $this->assertSame($status->issues->pluck('id')->values()->all(), $managed['issueIds']);
    }

    // ── TokenPresenter / LabelPresenter / KnowledgePresenter ────────

    public function test_token_presenter_shape(): void
    {
        $this->setupWorkspace();
        $this->owner->createToken('CLI');
        $token = $this->owner->tokens()->first();

        $presented = TokenPresenter::present($token);

        $this->assertSame($token->id, $presented['id']);
        $this->assertSame('CLI', $presented['name']);
        $this->assertSame($token->created_at->toISOString(), $presented['createdAt']);
        $this->assertNull($presented['lastUsedAt']);
    }

    public function test_label_presenter_shape(): void
    {
        $this->setupWorkspace();
        $label = CreateLabel::run($this->workspace, ['name' => 'front', 'color' => 'blue']);

        $this->assertSame(
            ['id' => $label->id, 'name' => 'front', 'color' => 'blue'],
            LabelPresenter::present($label),
        );
    }

    public function test_knowledge_presenter_list_item_and_detail(): void
    {
        $this->setupWorkspace();
        Queue::fake();
        $page = CreateKnowledgePage::run($this->workspace, $this->owner, [
            'title' => 'Guide de contribution',
            'body' => 'Les règles.',
        ]);

        $listItem = KnowledgePresenter::listItem($page);
        $this->assertSame(
            ['id', 'title', 'parentId', 'projectId', 'updatedAt'],
            array_keys($listItem),
        );
        $this->assertSame('Guide de contribution', $listItem['title']);

        $detail = KnowledgePresenter::detail($page->load('lastEditor'));
        $this->assertSame('Les règles.', $detail['body']);
        $this->assertSame($this->owner->name, $detail['lastEditor']);
        $this->assertSame($page->updated_at->toISOString(), $detail['updatedAt']);
    }

    // ── IssuePresenter — childSummary ────────────────────────────────

    public function test_child_summary_exposes_the_status_id_and_category(): void
    {
        $this->setupWorkspace();
        $status = $this->workspace->statuses()->where('category', 'in_progress')->first();

        $issue = CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => 'Enfant',
            'status_id' => $status->id,
        ]);

        $child = IssuePresenter::childSummary($issue);

        $this->assertSame($issue->id, $child['id']);
        $this->assertSame('Enfant', $child['title']);
        $this->assertSame($status->id, $child['statusId']);
        $this->assertSame($status->name, $child['statusName']);
        $this->assertSame('in_progress', $child['statusCategory']);
        $this->assertSame([], $child['assignees']);
    }

    // ── IssuePresenter — detail ──────────────────────────────────────

    public function test_detail_includes_the_descendant_summary(): void
    {
        $this->setupWorkspace();
        $backlog = $this->workspace->statuses()->where('category', 'backlog')->first();
        $done = $this->workspace->statuses()->where('category', 'done')->first();

        $parent = CreateIssue::run($this->workspace, ['project_id' => $this->project->id, 'title' => 'Parent']);
        $child = CreateIssue::run($this->workspace, ['project_id' => $this->project->id, 'title' => 'Child']);
        $grandchild = CreateIssue::run($this->workspace, ['project_id' => $this->project->id, 'title' => 'Grandchild', 'status_id' => $done->id]);
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        // An archived descendant never counts.
        $archived = CreateIssue::run($this->workspace, ['project_id' => $this->project->id, 'title' => 'Archived']);
        SetIssueParent::run($archived, $parent->id);
        $archived->update(['archived_at' => now()]);

        $descendants = app(LoadActiveDescendants::class)->run(collect([$parent]));
        $detail = IssuePresenter::detail($parent, null, [], $descendants);

        $this->assertSame(['total' => 2, 'byStatus' => [
            $backlog->id => 1,
            $done->id => 1,
        ]], $detail['descendants']);
    }
}
