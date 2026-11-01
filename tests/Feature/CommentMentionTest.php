<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\CommentMention;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CommentMentionTest extends TestCase
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

    private function addUser(Workspace $workspace, string $role, string $handle, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'handle' => $handle]);
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function addProject(Workspace $workspace, User $owner, string $name): Project
    {
        return CreateProject::run($workspace, $owner, ['name' => $name]);
    }

    private function addIssue(Workspace $workspace, Project $project, string $title): Issue
    {
        return CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title]);
    }

    public function test_a_comment_with_a_user_mention_creates_one_unread_row(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        $comment = AddComment::run($issue, $owner, 'Coucou @alice, peux-tu regarder ?');

        $this->assertDatabaseHas('comment_mentions', [
            'workspace_id' => $workspace->id,
            'comment_id' => $comment->id,
            'user_id' => $alice->id,
            'type' => 'user',
            'project_id' => null,
            'read_at' => null,
        ]);
        $this->assertDatabaseCount('comment_mentions', 1);
    }

    public function test_a_comment_without_mention_creates_no_row(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $this->addUser($workspace, 'member', 'alice', 'Alice');

        AddComment::run($issue, $owner, 'Aucune mention ici, juste un mail foo@alice.com.');

        $this->assertDatabaseCount('comment_mentions', 0);
    }

    public function test_a_project_mention_creates_one_row_per_project_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');
        $bob = $this->addUser($workspace, 'member', 'bob', 'Bob');
        $project->projectMembers()->create(['user_id' => $alice->id, 'role' => 'member']);
        $project->projectMembers()->create(['user_id' => $bob->id, 'role' => 'member']);

        // The author is a project member too (auto-onboarded) but never notified.
        AddComment::run($issue, $owner, '@site-vitrine on y va.');

        $this->assertDatabaseCount('comment_mentions', 2);
        $this->assertDatabaseHas('comment_mentions', [
            'user_id' => $alice->id,
            'type' => 'project',
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseHas('comment_mentions', [
            'user_id' => $bob->id,
            'type' => 'project',
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('comment_mentions', ['user_id' => $owner->id]);
    }

    public function test_editing_a_comment_replaces_its_mentions(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');
        $bob = $this->addUser($workspace, 'member', 'bob', 'Bob');

        $comment = AddComment::run($issue, $owner, 'Hey @alice');
        $this->assertDatabaseCount('comment_mentions', 1);

        UpdateComment::run($comment, 'Hey @bob now');

        $this->assertDatabaseCount('comment_mentions', 1);
        $this->assertDatabaseMissing('comment_mentions', ['user_id' => $alice->id]);
        $this->assertDatabaseHas('comment_mentions', [
            'comment_id' => $comment->id,
            'user_id' => $bob->id,
            'type' => 'user',
            'read_at' => null,
        ]);
    }

    public function test_a_guest_recipient_out_of_scope_receives_no_row(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectOne = $this->addProject($workspace, $owner, 'Project One');
        $projectTwo = $this->addProject($workspace, $owner, 'Project Two');
        $issue = $this->addIssue($workspace, $projectOne, 'Bug du formulaire');
        $gina = $this->addUser($workspace, 'guest', 'gina', 'Gina');
        // Gina is on project two's roster only.
        $projectTwo->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);

        AddComment::run($issue, $owner, 'Ping @gina sur ce ticket.');

        $this->assertDatabaseCount('comment_mentions', 0);
    }

    public function test_a_guest_author_cannot_mention_projects_outside_their_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectOne = $this->addProject($workspace, $owner, 'Project One');
        $projectTwo = $this->addProject($workspace, $owner, 'Project Two');
        $issue = $this->addIssue($workspace, $projectOne, 'Bug du formulaire');
        $gina = $this->addUser($workspace, 'guest', 'gina', 'Gina');
        $projectOne->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');
        $projectTwo->projectMembers()->create(['user_id' => $alice->id, 'role' => 'member']);

        // Gina comments on an issue of her roster project, mentioning a
        // project outside her perimeter: ignored.
        AddComment::run($issue, $gina, '@project-two on avance ?');

        $this->assertDatabaseCount('comment_mentions', 0);
    }

    public function test_a_handle_from_another_workspace_is_ignored(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');

        $bob = User::factory()->create(['name' => 'Bob', 'handle' => 'bob']);
        CreateWorkspace::run($bob, ['name' => 'WS B']);

        AddComment::run($issue, $owner, 'Salut @bob !');

        $this->assertDatabaseCount('comment_mentions', 0);
    }

    public function test_the_shared_unread_mentions_prop_is_exact_and_workspace_scoped(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        $this->travelTo(now()->subHour());
        $older = AddComment::run($issue, $owner, '@alice ancien point');
        $this->travelTo(now()->addMinutes(30));
        AddComment::run($issue, $owner, '@alice nouveau point');

        // One read mention, one unread mention for this account.
        CommentMention::query()->where('comment_id', $older->id)->update(['read_at' => now()]);

        // Alice also owns another workspace with no mention at all.
        $other = CreateWorkspace::run($alice, ['name' => 'ZZZ Personal']);

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unreadMentions', 1));

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $other->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unreadMentions', 0));
    }

    public function test_the_shared_unread_mentions_prop_is_zero_without_mentions(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        AddComment::run($issue, $owner, 'Bonjour à tous, pas de mention.');

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unreadMentions', 0));
    }

    public function test_the_mentions_page_lists_account_mentions_unread_first(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        $this->travelTo(now()->subHour());
        $older = AddComment::run($issue, $owner, '@alice ancien point');
        $this->travelTo(now()->addMinutes(30));
        $middle = AddComment::run($issue, $owner, '@alice point intermédiaire');
        $this->travelTo(now()->addMinutes(30));
        $newest = AddComment::run($issue, $owner, '@alice tout dernier point');

        CommentMention::query()->where('comment_id', $older->id)->update(['read_at' => now()]);

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('mentions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Mentions/Index')
                ->has('mentions', 3)
                ->where('unreadCount', 2)
                ->where('mentions.0.commentId', $newest->id)
                ->where('mentions.0.authorName', 'Owner')
                ->where('mentions.0.issueId', $issue->id)
                ->where('mentions.0.issueTitle', 'Bug du formulaire')
                ->where('mentions.0.excerpt', '@alice tout dernier point')
                ->where('mentions.0.readAt', null)
                ->where('mentions.1.commentId', $middle->id)
                ->where('mentions.1.readAt', null)
                ->where('mentions.2.commentId', $older->id)
                ->where('mentions.2.readAt', fn (string $readAt) => $readAt !== null));
    }

    public function test_the_mentions_page_hides_another_account_mentions(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issueOne = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $issueTwo = $this->addIssue($workspace, $project, 'Autre ticket');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');
        $bob = $this->addUser($workspace, 'member', 'bob', 'Bob');

        AddComment::run($issueOne, $owner, '@alice premier');
        AddComment::run($issueTwo, $owner, '@bob pour toi');
        AddComment::run($issueOne, $owner, '@alice second');

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('mentions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentions', 2)
                ->where('mentions.0.issueId', $issueOne->id)
                ->where('mentions.1.issueId', $issueOne->id));
    }

    public function test_reading_a_mention_marks_it_read_and_redirects_to_the_issue(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        AddComment::run($issue, $owner, '@alice à lire');
        $mention = CommentMention::query()->where('user_id', $alice->id)->firstOrFail();

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('mentions.read', $mention->id))
            ->assertRedirect(route('issues.show', $issue->id));

        $this->assertNotNull($mention->fresh()->read_at);
    }

    public function test_reading_all_mentions_marks_only_the_account_mentions_read(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');
        $bob = $this->addUser($workspace, 'member', 'bob', 'Bob');

        AddComment::run($issue, $owner, '@alice un');
        AddComment::run($issue, $owner, '@alice deux');
        AddComment::run($issue, $owner, '@bob un');

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('mentions.read-all'))
            ->assertRedirect(route('mentions.index'))
            ->assertSessionHas('status', __('flash.mention.read_all'));

        $this->assertSame(0, CommentMention::query()->where('user_id', $alice->id)->whereNull('read_at')->count());
        $this->assertSame(2, CommentMention::query()->where('user_id', $alice->id)->whereNotNull('read_at')->count());

        // Bob's mentions stay untouched.
        $this->assertSame(1, CommentMention::query()->where('user_id', $bob->id)->whereNull('read_at')->count());
    }

    public function test_a_guest_only_sees_mentions_within_their_roster(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $gina = $this->addUser($workspace, 'guest', 'gina', 'Gina');
        $rosterRow = $project->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);

        AddComment::run($issue, $owner, '@gina à lire');
        $this->assertDatabaseCount('comment_mentions', 1);

        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('mentions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentions', 1)
                ->where('unreadCount', 1));

        // The roster shrinks: the mention row stays but must disappear
        // from the guest's surfaces until the roster is restored.
        $rosterRow->delete();

        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('mentions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentions', 0)
                ->where('unreadCount', 0));

        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unreadMentions', 0));

        $project->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);

        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('mentions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentions', 1));
    }

    public function test_a_mention_of_another_workspace_returns_404(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = $this->addProject($workspace, $owner, 'Site Vitrine');
        $issue = $this->addIssue($workspace, $project, 'Bug du formulaire');
        $alice = $this->addUser($workspace, 'member', 'alice', 'Alice');

        AddComment::run($issue, $owner, '@alice à lire');
        $mention = CommentMention::query()->where('user_id', $alice->id)->firstOrFail();

        // Alice also belongs to another workspace: under its scope, the
        // mention of the first workspace must 404 like a non-existent one.
        $other = CreateWorkspace::run($alice, ['name' => 'ZZZ Personal']);

        $this->actingAs($alice)
            ->withSession(['workspace_id' => $other->id])
            ->post(route('mentions.read', $mention->id))
            ->assertNotFound();

        $this->assertNull($mention->fresh()->read_at);
    }

    public function test_the_shared_mentionables_prop_follows_the_account_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectOne = $this->addProject($workspace, $owner, 'Alpha One');
        $projectTwo = $this->addProject($workspace, $owner, 'Beta Two');
        $this->addUser($workspace, 'member', 'alice', 'Alice');
        $gina = $this->addUser($workspace, 'guest', 'gina', 'Gina');
        $projectOne->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);

        // Full member: every workspace member and project.
        $this->actingAs($owner)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentionables', 5)
                ->where('mentionables.0.handle', 'alice')
                ->where('mentionables.0.type', 'user')
                ->where('mentionables.1.handle', 'gina')
                ->where('mentionables.2.handle', 'owner')
                ->where('mentionables.3.handle', 'alpha-one')
                ->where('mentionables.3.type', 'project')
                ->where('mentionables.4.handle', 'beta-two'));

        // Guest: only the roster project and its members (herself included).
        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentionables', 3)
                ->where('mentionables.0.handle', 'gina')
                ->where('mentionables.0.type', 'user')
                ->where('mentionables.1.handle', 'owner')
                ->where('mentionables.1.type', 'user')
                ->where('mentionables.2.handle', 'alpha-one')
                ->where('mentionables.2.type', 'project'));
    }
}
