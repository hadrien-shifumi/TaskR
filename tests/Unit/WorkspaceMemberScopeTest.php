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
     * @param  array<int, string>  $projectIds
     */
    private function scopeMember(Membership $membership, array $projectIds): void
    {
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope($projectIds);
    }

    public function test_unscoped_member_keeps_unrestricted_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        $this->assertNull(app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_scoped_member_is_limited_to_granted_projects(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        $this->scopeMember($membership, [$alpha->id, $beta->id]);

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);

        $expected = [$alpha->id, $beta->id];
        sort($expected);
        sort($scope);

        $this->assertSame($expected, $scope);
    }

    public function test_scoped_member_scope_includes_owned_projects(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $owned = CreateProject::run($workspace, $owner, ['name' => 'Owned']);

        // The member owns "Owned" on the roster but is not in the granted scope.
        $owned->projectMembers()->create(['user_id' => $member->id, 'role' => 'owner']);
        $this->scopeMember($membership, [$alpha->id]);

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);

        $expected = [$alpha->id, $owned->id];
        sort($expected);
        sort($scope);

        $this->assertSame($expected, $scope);
    }

    public function test_scoped_member_with_empty_grant_sees_only_owned_projects(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $owned = CreateProject::run($workspace, $owner, ['name' => 'Owned']);
        $owned->projectMembers()->create(['user_id' => $member->id, 'role' => 'owner']);

        $this->scopeMember($membership, []);

        $this->assertSame([$owned->id], app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_scoped_member_roster_membership_does_not_extend_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $teamed = CreateProject::run($workspace, $owner, ['name' => 'Teamed']);

        // A plain roster row (team membership) must not widen the perimeter
        // of a scoped member — the roster stays the real team, not a scope.
        $teamed->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->scopeMember($membership, [$alpha->id]);

        $this->assertSame([$alpha->id], app(WorkspaceContext::class)->accessibleProjectIds($member));
    }

    public function test_scoped_member_remains_full_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $this->scopeMember($membership, [$alpha->id]);

        $this->assertTrue(app(WorkspaceContext::class)->isFullMember($member));
    }

    public function test_scoped_member_project_issue_and_knowledge_access_honor_scope(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Dans le périmètre']);
        $outScope = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $this->scopeMember($membership, [$inScope->id]);

        $issueIn = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Ticket visible']);
        $issueOut = CreateIssue::run($workspace, ['project_id' => $outScope->id, 'title' => 'Ticket masqué']);
        $pageIn = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dedans', 'body' => 'Corps', 'project_id' => $inScope->id]);
        $pageOut = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dehors', 'body' => 'Corps', 'project_id' => $outScope->id]);
        $pageWorkspace = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc workspace', 'body' => 'Corps']);

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canViewProject($member, $inScope));
        $this->assertFalse($context->canViewProject($member, $outScope));
        $this->assertTrue($context->canAccessIssue($member, $issueIn));
        $this->assertFalse($context->canAccessIssue($member, $issueOut));

        // A scoped member keeps the common surfaces: transverse knowledge
        // stays reachable — the difference with a guest.
        $this->assertTrue($context->canAccessKnowledgePage($member, $pageIn));
        $this->assertFalse($context->canAccessKnowledgePage($member, $pageOut));
        $this->assertTrue($context->canAccessKnowledgePage($member, $pageWorkspace));
    }

    public function test_scope_flush_picks_up_perimeter_changes(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $this->scopeMember($membership, [$alpha->id]);

        $context = app(WorkspaceContext::class);
        $this->assertSame([$alpha->id], $context->accessibleProjectIds($member));

        // Simulate a perimeter edit within the same request lifetime: the
        // memo must be invalidated so the fresh rows are picked up.
        $membership->syncProjectScope([$beta->id]);
        $context->flushAccessibleProjectScope($member);

        $this->assertSame([$beta->id], $context->accessibleProjectIds($member));
    }
}
