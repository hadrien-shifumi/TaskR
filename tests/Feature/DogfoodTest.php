<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Change task 7.3 — minimal dogfood: register a user, get a workspace
 * with default statuses, run two projects with an epic/story/task
 * hierarchy, comment, and filter the list.
 */
class DogfoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_journey(): void
    {
        // Register: workspace + 4 default statuses are created.
        $this->post('/register', [
            'name' => 'Hadrien',
            'email' => 'hadrien@taskr.dev',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $owner = User::where('email', 'hadrien@taskr.dev')->firstOrFail();

        // Two projects.
        $this->actingAs($owner)->post('/projects', ['name' => 'Taskr'])->assertRedirect();
        $this->actingAs($owner)->post('/projects', ['name' => 'Client Alpha'])->assertRedirect();

        $this->assertDatabaseCount('projects', 2);

        $workspace = $owner->memberships()->first()->workspace;
        app(WorkspaceContext::class)->set($workspace);
        $statuses = $workspace->statuses()->orderBy('position')->get();
        $backlog = $statuses[0];
        $inProgress = $statuses[1];
        $taskrProject = $workspace->projects()->where('name', 'Taskr')->firstOrFail();

        // Epic > Story > Task hierarchy on Taskr.
        $epicId = $this->actingAs($owner)
            ->post('/issues', ['project_id' => $taskrProject->id, 'title' => 'Epic: MVP Taskr'])
            ->assertRedirect()
            ->getSession()->get('status');
        $this->assertStringContainsString('created', $epicId);

        $epic = $workspace->issues()->where('title', 'Epic: MVP Taskr')->first();

        $storyId = $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $taskrProject->id,
                'title' => 'Story: gestion des issues',
                'status_id' => $inProgress->id,
                'priority' => 'high',
                'due_date' => now()->addWeek()->toDateString(),
            ])->assertRedirect()->getSession()->get('status');
        $this->assertStringContainsString('created', $storyId);

        $story = $workspace->issues()->where('title', 'Story: gestion des issues')->first();
        $this->actingAs($owner)->post("/issues/{$story->id}/parent", ['parent_id' => $epic->id])->assertRedirect();

        $taskId = $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $taskrProject->id,
                'title' => 'Task: formulaire de creation',
            ])->assertRedirect()->getSession()->get('status');
        $this->assertStringContainsString('created', $taskId);

        $task = $workspace->issues()->where('title', 'Task: formulaire de creation')->first();
        $this->actingAs($owner)->post("/issues/{$task->id}/parent", ['parent_id' => $story->id])->assertRedirect();

        // Comment on the story.
        $this->actingAs($owner)
            ->post("/issues/{$story->id}/comments", ['body' => 'Il faut aussi pouvoir filtrer par priorite.'])
            ->assertRedirect();

        // Filter by status: only the story is in progress.
        $this->actingAs($owner)
            ->get("/issues?status={$inProgress->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Story: gestion des issues'));

        // The epic detail shows its child (the story).
        $this->actingAs($owner)
            ->get("/issues/{$epic->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.children.0.id', $story->id));

        // The story shows its own child (the task) and the comment.
        $this->actingAs($owner)
            ->get("/issues/{$story->id}", [
                'X-Inertia-Partial-Component' => 'Issues/Show',
                'X-Inertia-Partial-Data' => 'issue,comments',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.parent.id', $epic->id)
                ->where('issue.children.0.id', $task->id)
                ->has('comments', 1)
                ->where('comments.0.body', 'Il faut aussi pouvoir filtrer par priorite.'));

        // Backlog filter still finds the epic and the task.
        $this->actingAs($owner)
            ->get("/issues?status={$backlog->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues', 2));
    }
}
