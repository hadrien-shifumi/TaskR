<?php

namespace Tests\Unit;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\MentionType;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MentionParser;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionParserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create(['name' => 'Owner', 'handle' => 'owner']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addUser(Workspace $workspace, string $handle): User
    {
        $user = User::factory()->create(['name' => ucfirst($handle), 'handle' => $handle]);
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $user;
    }

    public function test_extracts_a_single_handle(): void
    {
        $this->assertSame(['alice'], MentionParser::extractHandles('Coucou @alice !'));
    }

    public function test_extracts_multiple_handles(): void
    {
        $this->assertSame(
            ['alice', 'bob'],
            MentionParser::extractHandles('Hey @alice and @bob, check this.'),
        );
    }

    public function test_extracts_handles_at_line_start_and_after_punctuation(): void
    {
        $this->assertSame(
            ['bob', 'alice'],
            MentionParser::extractHandles("@bob\n(@alice) yes,@alice!"),
        );
    }

    public function test_email_addresses_are_not_mentions(): void
    {
        $this->assertSame([], MentionParser::extractHandles('foo@bar.com'));
        $this->assertSame(
            ['alice'],
            MentionParser::extractHandles('Contact bob at bob@taskr.io, or @alice.'),
        );
    }

    public function test_duplicate_handles_are_deduplicated(): void
    {
        $this->assertSame(['bob'], MentionParser::extractHandles('@bob @BOB @Bob'));
    }

    public function test_handles_are_matched_case_insensitively_during_resolution(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $bob = $this->addUser($workspace, 'bob');

        $targets = MentionParser::resolve($workspace, $owner, 'Salut @BOB !', 'irrelevant-project-id');

        $this->assertCount(1, $targets);
        $this->assertSame($bob->id, $targets[0]['user']->id);
        $this->assertSame(MentionType::User, $targets[0]['type']);
        $this->assertNull($targets[0]['projectId']);
    }

    public function test_unknown_handles_are_ignored_silently(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $this->addUser($workspace, 'bob');

        $targets = MentionParser::resolve($workspace, $owner, 'Salut @ghost !', 'irrelevant-project-id');

        $this->assertSame([], $targets);
    }

    public function test_self_mention_is_excluded(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $targets = MentionParser::resolve($workspace, $owner, 'Moi @owner je me mentionne.', 'irrelevant-project-id');

        $this->assertSame([], $targets);
    }

    public function test_a_direct_mention_wins_over_a_project_mention_of_the_same_user(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site Vitrine']);
        $bob = $this->addUser($workspace, 'bob');
        $project->projectMembers()->create(['user_id' => $bob->id, 'role' => 'member']);

        // @bob is both a workspace member and... not a project handle, but the
        // user is a member of the mentioned project: deduplication keeps the
        // direct mention only.
        $targets = MentionParser::resolve($workspace, $owner, '@bob @site-vitrine on y va.', $project->id);

        $this->assertCount(1, $targets);
        $this->assertSame($bob->id, $targets[0]['user']->id);
        $this->assertSame(MentionType::User, $targets[0]['type']);
    }

    public function test_a_project_mention_is_exploded_per_project_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site Vitrine']);
        $alice = $this->addUser($workspace, 'alice');
        $bob = $this->addUser($workspace, 'bob');
        $project->projectMembers()->create(['user_id' => $alice->id, 'role' => 'member']);
        $project->projectMembers()->create(['user_id' => $bob->id, 'role' => 'member']);

        // The author is a project member too (auto-onboarded) but is never
        // notified of their own mention.
        $targets = MentionParser::resolve($workspace, $owner, '@site-vitrine on y va.', $project->id);

        $this->assertCount(2, $targets);
        $this->assertSame($alice->id, $targets[0]['user']->id);
        $this->assertSame(MentionType::Project, $targets[0]['type']);
        $this->assertSame($project->id, $targets[0]['projectId']);
        $this->assertSame($bob->id, $targets[1]['user']->id);
        $this->assertSame(MentionType::Project, $targets[1]['type']);
        $this->assertSame($project->id, $targets[1]['projectId']);
    }
}
