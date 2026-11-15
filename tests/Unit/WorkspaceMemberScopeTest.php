<?php

namespace Tests\Unit;

use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The project team is the single source of project access: a member or
 * a guest sees exactly the projects they hold a roster line on, and the
 * workspace owner alone stays unrestricted. These tests pin that
 * resolver doctrine.
 */
class WorkspaceMemberScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    /**
     * @return array{0: User, 1: Membership}
     */
    private function addMember(Workspace $workspace): array
    {
        $user = User::factory()->create();
        $membership = Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$user, $membership];
    }

    /**
     * @param  array<int, Project>  $projects
     */
    private function joinTeams(User $user, array $projects, string $role = 'member'): void
    {
        foreach ($projects as $project) {
            $project->projectMembers()->create(['user_id' => $user->id, 'role' => $role]);
        }
    }

    public function test_owner_scope_stays_unrestricted(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        // The workspace owner never needs a team line: unrestricted by
        // definition, with or without roster rows.
        $this->assertNull(app(WorkspaceContext::class)->accessibleProjectIds($owner));
    }

    public function test_member_scope_is_roster_driven(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        $this->joinTeams($member, [$alpha, $beta]);

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);

        $expected = [$alpha->id, $beta->id];
        sort($expected);
        sort($scope);

        $this->assertSame($expected, $scope);
    }

    public function test_member_without_roster_rows_sees_no_project(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $this->assertSame([], app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_member_roster_covers_the_projects_they_own(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        // Creating a project onboards the creator as its team owner: an
        // owned project is never invisible to its owner.
        $owned = CreateProject::run($workspace, $member, ['name' => 'Owned']);

        $this->assertSame([$owned->id], app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_member_roster_role_never_widens_or_shrinks_access(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $teamed = CreateProject::run($workspace, $owner, ['name' => 'Teamed']);

        // Whatever the line says — owner, manager, member or guest — the
        // access is the same: being on the team is the grant.
        $this->joinTeams($member, [$teamed], 'manager');

        $this->assertSame([$teamed->id], app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_roster_member_remains_full_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $this->joinTeams($member, [$alpha]);

        $this->assertTrue(app(WorkspaceContext::class)->isFullMember($member));
    }

    public function test_roster_member_project_issue_and_knowledge_access_honor_the_team(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $teamed = CreateProject::run($workspace, $owner, ['name' => 'Dans l\'équipe']);
        $unTeamed = CreateProject::run($workspace, $owner, ['name' => 'Hors équipe']);
        $this->joinTeams($member, [$teamed]);

        $issueIn = CreateIssue::run($workspace, ['project_id' => $teamed->id, 'title' => 'Ticket visible']);
        $issueOut = CreateIssue::run($workspace, ['project_id' => $unTeamed->id, 'title' => 'Ticket masqué']);
        $pageIn = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dedans', 'body' => 'Corps', 'project_id' => $teamed->id]);
        $pageOut = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dehors', 'body' => 'Corps', 'project_id' => $unTeamed->id]);
        $pageWorkspace = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc workspace', 'body' => 'Corps']);

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canViewProject($member, $teamed));
        $this->assertFalse($context->canViewProject($member, $unTeamed));
        $this->assertTrue($context->canAccessIssue($member, $issueIn));
        $this->assertFalse($context->canAccessIssue($member, $issueOut));

        // A roster member keeps the common surfaces: transverse knowledge
        // stays reachable — the difference with a guest.
        $this->assertTrue($context->canAccessKnowledgePage($member, $pageIn));
        $this->assertFalse($context->canAccessKnowledgePage($member, $pageOut));
        $this->assertTrue($context->canAccessKnowledgePage($member, $pageWorkspace));
    }

    public function test_scope_flush_picks_up_roster_changes(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $this->joinTeams($member, [$alpha]);

        $context = app(WorkspaceContext::class);
        $this->assertSame([$alpha->id], $context->accessibleProjectIds($member));

        // Simulate a roster edit within the same request lifetime: the
        // memo must be invalidated so the fresh rows are picked up.
        $beta->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);
        $context->flushAccessibleProjectScope($member);

        $scope = $context->accessibleProjectIds($member);
        sort($scope);
        $expected = [$alpha->id, $beta->id];
        sort($expected);
        $this->assertSame($expected, $scope);
    }
}
