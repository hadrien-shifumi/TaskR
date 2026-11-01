<?php

namespace Tests\Feature\Audit;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\ArchiveIssue;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\DeleteIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Issues\SetIssueDependencies;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Issues\SetLabels;
use App\Actions\Issues\UpdateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: User}
     */
    private function setupWorkspaceWithMember(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $member];
    }

    private function doneStatusId(Workspace $workspace): string
    {
        return Status::query()->where('name', 'Done')->firstOrFail()->id;
    }

    public function test_create_issue_emits_issue_created_with_created_fields(): void
    {
        [$owner, $workspace, $member] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $label = Label::factory()->create(['workspace_id' => $workspace->id]);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Page de profil',
            'description' => 'Avatar et bio',
            'priority' => 'high',
            'due_date' => '2030-01-01',
            'assignees' => [$member->id],
            'label_ids' => [$label->id],
        ], $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueCreated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($project->id, $event->project_id);
        $this->assertSame([
            'title' => ['before' => null, 'after' => 'Page de profil'],
            'description' => ['before' => null, 'after' => 'Avatar et bio'],
            'status' => ['before' => null, 'after' => 'Backlog'],
            'priority' => ['before' => null, 'after' => 'high'],
            'due_date' => ['before' => null, 'after' => '2030-01-01'],
            'labels' => ['before' => null, 'after' => [$label->id]],
            'assignees' => ['before' => null, 'after' => [$member->id]],
        ], $event->changes);
    }

    public function test_create_issue_without_actor_records_a_null_actor(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Interne']);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueCreated->value)->firstOrFail();

        $this->assertNull($event->actor_id);
    }

    public function test_update_issue_emits_issue_updated_for_title_and_description(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Ancien titre',
            'description' => 'Ancienne description',
        ], $owner);

        UpdateIssue::run($issue, [
            'title' => 'Nouveau titre',
            'description' => 'Nouvelle description',
        ], $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueUpdated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame([
            'title' => ['before' => 'Ancien titre', 'after' => 'Nouveau titre'],
            'description' => ['before' => 'Ancienne description', 'after' => 'Nouvelle description'],
        ], $event->changes);
    }

    public function test_update_issue_emits_priority_changed_and_due_date_changed(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Stable',
            'priority' => 'none',
        ], $owner);

        UpdateIssue::run($issue, [
            'title' => 'Stable',
            'priority' => 'urgent',
            'due_date' => '2030-06-01',
        ], $owner);

        $priority = AuditEvent::query()->where('type', AuditEventType::IssuePriorityChanged->value)->firstOrFail();
        $dueDate = AuditEvent::query()->where('type', AuditEventType::IssueDueDateChanged->value)->firstOrFail();

        $this->assertSame(['priority' => ['before' => 'none', 'after' => 'urgent']], $priority->changes);
        $this->assertSame($owner->id, $priority->actor_id);
        $this->assertSame(['due_date' => ['before' => null, 'after' => '2030-06-01']], $dueDate->changes);
    }

    public function test_update_issue_without_changes_emits_nothing(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Stable',
            'description' => 'Description',
        ], $owner);

        $countBefore = AuditEvent::query()->count();

        UpdateIssue::run($issue, [
            'title' => 'Stable',
            'description' => 'Description',
            'priority' => 'none',
            'due_date' => null,
        ], $owner);

        $this->assertSame($countBefore, AuditEvent::query()->count());
    }

    public function test_move_issue_emits_status_changed_with_the_transition(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'A bouger',
        ], $owner);

        $inProgress = Status::query()->where('name', 'In Progress')->firstOrFail();
        MoveIssue::run($issue, $inProgress->id, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueStatusChanged->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame([
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ], $event->changes);
    }

    public function test_delete_issue_emits_deleted_with_the_loss_inventory(): void
    {
        [$owner, $workspace, $member] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $label = Label::factory()->create(['workspace_id' => $workspace->id]);
        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Parent'], $owner);

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'A supprimer',
            'description' => 'Description complete',
            'priority' => 'high',
            'due_date' => '2030-02-02',
        ], $owner);
        SetIssueEstimate::run($issue, 3.5, $owner);
        SetLabels::run($issue, [$label->id], $owner);
        SetAssignees::run($issue, [$member->id], $owner);
        SetIssueParent::run($issue, $parent->id, $owner);
        AddComment::run($issue, $owner, 'Premier commentaire');
        AddComment::run($issue, $member, 'Deuxieme commentaire');
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'note' => 'Analyse']);

        DeleteIssue::run($issue, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueDeleted->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($project->id, $event->project_id);
        // The nullOnDelete FK clears the subject link once the row is gone:
        // the event stays readable through its project and its inventory.
        $this->assertNull($event->issue_id);

        $changes = $event->changes;
        $this->assertSame(2, $changes['comments_count']);
        $this->assertSame(1, $changes['time_entries_count']);
        $this->assertSame('A supprimer', $changes['title']);
        $this->assertSame('Description complete', $changes['description']);
        $this->assertSame('Backlog', $changes['status']);
        $this->assertSame('high', $changes['priority']);
        $this->assertSame('2030-02-02', $changes['due_date']);
        $this->assertSame(3.5, $changes['estimated_hours']);
        $this->assertSame([$label->id], $changes['labels']);
        $this->assertSame([$member->id], $changes['assignees']);
        $this->assertSame($parent->id, $changes['parent_id']);
    }

    public function test_archive_issue_emits_archived_then_restored(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Terminee',
            'status_id' => $this->doneStatusId($workspace),
        ], $owner);

        ArchiveIssue::run($issue, $owner);

        $archived = AuditEvent::query()->where('type', AuditEventType::IssueArchived->value)->firstOrFail();
        $this->assertSame($owner->id, $archived->actor_id);
        $this->assertSame($issue->id, $archived->issue_id);

        ArchiveIssue::run($issue->refresh(), $owner);

        $restored = AuditEvent::query()->where('type', AuditEventType::IssueRestored->value)->firstOrFail();
        $this->assertSame($issue->id, $restored->issue_id);
        $this->assertSame($owner->id, $restored->actor_id);
    }

    public function test_set_assignees_emits_assigned_and_unassigned_by_diff(): void
    {
        [$owner, $workspace, $member] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Equipee'], $owner);

        SetAssignees::run($issue, [$member->id], $owner);

        $assigned = AuditEvent::query()->where('type', AuditEventType::IssueAssigned->value)->firstOrFail();
        $this->assertSame($issue->id, $assigned->issue_id);
        $this->assertSame($owner->id, $assigned->actor_id);
        $this->assertSame(['assignees_added' => [$member->id]], $assigned->changes);

        SetAssignees::run($issue, [$owner->id], $owner);

        $unassigned = AuditEvent::query()->where('type', AuditEventType::IssueUnassigned->value)->firstOrFail();
        $this->assertSame(['assignees_removed' => [$member->id]], $unassigned->changes);

        $secondAssigned = AuditEvent::query()
            ->where('type', AuditEventType::IssueAssigned->value)
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($secondAssigned);
        $this->assertSame(['assignees_added' => [$owner->id]], $secondAssigned->changes);
    }

    public function test_set_issue_parent_emits_parent_set_then_parent_removed(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Parent'], $owner);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Enfant'], $owner);

        SetIssueParent::run($issue, $parent->id, $owner);

        $set = AuditEvent::query()->where('type', AuditEventType::IssueParentSet->value)->firstOrFail();
        $this->assertSame($issue->id, $set->issue_id);
        $this->assertSame($owner->id, $set->actor_id);
        $this->assertSame(['parent_id' => ['before' => null, 'after' => $parent->id]], $set->changes);

        SetIssueParent::run($issue, null, $owner);

        $removed = AuditEvent::query()->where('type', AuditEventType::IssueParentRemoved->value)->firstOrFail();
        $this->assertSame(['parent_id' => ['before' => $parent->id, 'after' => null]], $removed->changes);
    }

    public function test_set_labels_emits_issue_updated_with_the_labels_diff(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $label = Label::factory()->create(['workspace_id' => $workspace->id]);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Labellee'], $owner);

        SetLabels::run($issue, [$label->id], $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueUpdated->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame([
            'labels' => ['before' => [], 'after' => [$label->id]],
        ], $event->changes);
    }

    public function test_set_issue_estimate_emits_estimate_changed(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Estimee'], $owner);

        SetIssueEstimate::run($issue, 4.5, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::IssueEstimateChanged->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(['estimated_hours' => ['before' => null, 'after' => 4.5]], $event->changes);
    }

    public function test_issue_events_are_isolated_per_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Isolee'], $owner);

        $this->assertSame(1, AuditEvent::query()->where('type', AuditEventType::IssueCreated->value)->count());

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->assertSame(0, AuditEvent::query()->count());

        app(WorkspaceContext::class)->set($workspace);
        $this->assertSame(1, AuditEvent::query()->where('type', AuditEventType::IssueCreated->value)->count());
    }

    public function test_set_issue_dependencies_is_not_journaled(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithMember();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Bloquee'], $owner);
        $blocker = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Bloquante'], $owner);

        $countBefore = AuditEvent::query()->count();

        SetIssueDependencies::run($issue, [$blocker->id], $owner);

        $this->assertSame($countBefore, AuditEvent::query()->count());
    }
}
