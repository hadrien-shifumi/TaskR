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

class WorkspaceGuestScopeTest extends TestCase
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

    private function addUser(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    public function test_owner_and_member_have_unrestricted_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');

        $context = app(WorkspaceContext::class);

        $this->assertNull($context->accessibleProjectIds($owner));
        $this->assertNull($context->accessibleProjectIds($member));
    }

    public function test_guest_scope_is_limited_to_roster_projects(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');
        $projectA = CreateProject::run($workspace, $owner, ['name' => 'A']);
        $projectB = CreateProject::run($workspace, $owner, ['name' => 'B']);
        CreateProject::run($workspace, $owner, ['name' => 'C']);

        $projectA->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);
        $projectB->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($guest);

        $expected = [$projectA->id, $projectB->id];
        sort($expected);
        sort($scope);

        $this->assertSame($expected, $scope);
    }

    public function test_guest_without_roster_rows_has_empty_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');

        $this->assertSame([], app(WorkspaceContext::class)->accessibleProjectIds($guest));
    }

    public function test_non_member_has_empty_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $outsider = User::factory()->create();

        $this->assertSame([], app(WorkspaceContext::class)->accessibleProjectIds($outsider));
    }

    public function test_can_view_project_honors_guest_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');
        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Dans le périmètre']);
        $outScope = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $inScope->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canViewProject($guest, $inScope));
        $this->assertFalse($context->canViewProject($guest, $outScope));
        $this->assertTrue($context->canViewProject($member, $outScope));
    }

    public function test_can_access_issue_honors_guest_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');
        $projectA = CreateProject::run($workspace, $owner, ['name' => 'A']);
        $projectB = CreateProject::run($workspace, $owner, ['name' => 'B']);
        $projectA->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

        $issueIn = CreateIssue::run($workspace, ['project_id' => $projectA->id, 'title' => 'Ticket accessible']);
        $issueOut = CreateIssue::run($workspace, ['project_id' => $projectB->id, 'title' => 'Ticket masqué']);

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canAccessIssue($guest, $issueIn));
        $this->assertFalse($context->canAccessIssue($guest, $issueOut));
    }

    public function test_can_access_knowledge_page_honors_guest_scope(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');
        $projectA = CreateProject::run($workspace, $owner, ['name' => 'A']);
        $projectB = CreateProject::run($workspace, $owner, ['name' => 'B']);
        $projectA->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

        $pageIn = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet A',
            'body' => 'Corps',
            'project_id' => $projectA->id,
        ]);
        $pageOut = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet B',
            'body' => 'Corps',
            'project_id' => $projectB->id,
        ]);
        $pageWorkspace = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc workspace',
            'body' => 'Corps',
        ]);

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canAccessKnowledgePage($guest, $pageIn));
        $this->assertFalse($context->canAccessKnowledgePage($guest, $pageOut));
        $this->assertFalse($context->canAccessKnowledgePage($guest, $pageWorkspace));
        $this->assertTrue($context->canAccessKnowledgePage($member, $pageWorkspace));
    }

    public function test_can_use_workspace_and_is_full_member_distinguish_guest(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');
        $outsider = User::factory()->create();

        $context = app(WorkspaceContext::class);

        $this->assertTrue($context->canUseWorkspace($owner));
        $this->assertTrue($context->canUseWorkspace($member));
        $this->assertTrue($context->canUseWorkspace($guest));
        $this->assertFalse($context->canUseWorkspace($outsider));

        $this->assertTrue($context->isFullMember($owner));
        $this->assertTrue($context->isFullMember($member));
        $this->assertFalse($context->isFullMember($guest));
        $this->assertFalse($context->isFullMember($outsider));
    }
}
