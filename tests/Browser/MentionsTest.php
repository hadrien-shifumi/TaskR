<?php

use App\Actions\Issues\CreateIssue;
use App\Models\CommentMention;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * A second full member of the workspace, the one being mentioned.
 */
function addMentionedMember(Workspace $workspace, string $name = 'Bob Martin'): User
{
    $member = User::factory()->create(['name' => $name]);

    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);

    return $member->refresh();
}

/**
 * An issue of the workspace, opened by the given account.
 */
function seedMentionIssue(Workspace $workspace, User $author, string $title = 'Page de profil'): string
{
    app(WorkspaceContext::class)->set($workspace);

    return CreateIssue::run($workspace, [
        'project_id' => $workspace->projects()->firstOrFail()->id,
        'title' => $title,
    ])->id;
}

it('test_mention_autocomplete_inserts_handle_in_comment_editor', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = addMentionedMember($workspace);
    $issueId = seedMentionIssue($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issueId);

    // Typing "@" opens the autocomplete of the workspace members.
    $page->fill('[data-test="comment-body"]', '@')
        ->assertVisible('[data-test="mention-autocomplete"]')
        ->assertPresent('[data-test="mention-option"][data-handle="'.$member->handle.'"]');

    $page->click('[data-test="mention-option"][data-handle="'.$member->handle.'"]');

    $page->assertValue('[data-test="comment-body"]', '@'.$member->handle.' ')
        ->assertNotPresent('[data-test="mention-autocomplete"]');

    $page->assertNoSmoke();
});

it('test_posting_comment_creates_comment_mention', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = addMentionedMember($workspace);
    $issueId = seedMentionIssue($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issueId);

    $page->fill('[data-test="comment-body"]', '@'.$member->handle.' peux-tu relire ?')
        ->click('[data-test="comment-submit"]')
        ->waitForEvent('networkidle');

    $page->assertSee('peux-tu relire ?');

    app(WorkspaceContext::class)->set($workspace);
    $mention = CommentMention::query()
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect($mention->workspace_id)->toBe($workspace->id)
        ->and($mention->read_at)->toBeNull()
        ->and($mention->comment->issue_id)->toBe($issueId)
        ->and($mention->comment->author_id)->toBe($owner->id);

    $page->assertNoSmoke();
});

it('test_mentioned_member_sees_sidebar_badge_and_unread_mentions_page', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = addMentionedMember($workspace);
    $issueId = seedMentionIssue($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issueId);
    $page->fill('[data-test="comment-body"]', '@'.$member->handle.' peux-tu relire ?')
        ->click('[data-test="comment-submit"]')
        ->waitForEvent('networkidle');

    // The badge is computed per Inertia visit: switching account and
    // navigating is enough, no real-time wait involved.
    $this->actingAs($member);
    $page = visit('/');
    app(WorkspaceContext::class)->set($workspace);

    $page->assertVisible('[data-test="mentions-badge"]')
        ->assertSeeIn('[data-test="mentions-badge"]', '1');

    $page->navigate('/mentions');

    $page->assertPresent('[data-test="mentions-list"]')
        ->assertCount('[data-test="mention-row"]', 1)
        ->assertVisible('[data-test="mention-unread-badge"]')
        ->assertSee('Page de profil')
        ->assertSee('peux-tu relire ?');

    $page->assertNoSmoke();
});

it('test_mark_mention_read_and_read_all', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = addMentionedMember($workspace);
    $firstIssue = seedMentionIssue($workspace, $owner, 'Page de profil');
    $secondIssue = seedMentionIssue($workspace, $owner, 'Facturation');

    $this->actingAs($owner);
    $page = visit('/issues/'.$firstIssue);
    $page->fill('[data-test="comment-body"]', '@'.$member->handle.' premier ping')
        ->click('[data-test="comment-submit"]')
        ->waitForEvent('networkidle');

    $page->navigate('/issues/'.$secondIssue);
    $page->fill('[data-test="comment-body"]', '@'.$member->handle.' second ping')
        ->click('[data-test="comment-submit"]')
        ->waitForEvent('networkidle');

    $this->actingAs($member);
    $page = visit('/mentions');

    $page->assertCount('[data-test="mention-row"]', 2)
        ->assertCount('[data-test="mention-unread-badge"]', 2);

    // Opening a row marks it read server-side and lands on its issue.
    $page->click('[data-test="mention-row"]:has-text("premier ping")')
        ->waitForEvent('networkidle');

    $page->assertSee('premier ping');

    $page->navigate('/mentions');
    $page->assertCount('[data-test="mention-row"]', 2)
        ->assertCount('[data-test="mention-unread-badge"]', 1);

    app(WorkspaceContext::class)->set($workspace);
    expect(CommentMention::query()->where('user_id', $member->id)->whereNull('read_at')->count())->toBe(1);

    // Read all clears the remaining unread mention and the sidebar badge.
    $page->click('[data-test="mentions-read-all"]')
        ->waitForEvent('networkidle');

    $page->assertNotPresent('[data-test="mention-unread-badge"]')
        ->assertNotPresent('[data-test="mentions-read-all"]')
        ->assertNotPresent('[data-test="mentions-badge"]');

    app(WorkspaceContext::class)->set($workspace);
    expect(CommentMention::query()->where('user_id', $member->id)->whereNull('read_at')->count())->toBe(0);

    $page->assertNoSmoke();
});

it('test_unknown_handle_creates_no_mention', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = addMentionedMember($workspace);
    $issueId = seedMentionIssue($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issueId);

    $page->fill('[data-test="comment-body"]', '@nobody coucou')
        ->click('[data-test="comment-submit"]')
        ->waitForEvent('networkidle');

    $page->assertSee('coucou');

    app(WorkspaceContext::class)->set($workspace);
    expect(CommentMention::query()->count())->toBe(0)
        ->and($workspace->issues()->findOrFail($issueId)->comments()->count())->toBe(1);

    $page->assertNoSmoke();
});
