<?php

namespace Database\Seeders;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\Label;
use App\Models\Project;
use App\Models\Status;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds a complete demo dataset spanning every model.
 *
 * Demo accounts — password: "password"
 *   hadrien@taskr.dev   owner of both workspaces
 *   alice@taskr.dev     Acme Studio member
 *   marco@taskr.dev     Acme Studio member
 *   sofia@taskr.dev     Acme Studio member
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $hadrien = $this->createUser('Hadrien Delphin', 'hadrien@taskr.dev');
        $alice = $this->createUser('Alice Martin', 'alice@taskr.dev');
        $marco = $this->createUser('Marco Rossi', 'marco@taskr.dev');
        $sofia = $this->createUser('Sofia Nilsson', 'sofia@taskr.dev');

        $this->seedAcmeStudio($hadrien, $alice, $marco, $sofia);
        $this->seedPersonal($hadrien);
    }

    private function createUser(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    /**
     * Fetch a workspace's statuses keyed by name, bypassing the global
     * workspace scope (no workspace context exists during seeding).
     *
     * @return Collection<string, Status>
     */
    private function statusesFor(Workspace $workspace): Collection
    {
        return Status::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('position')
            ->get()
            ->keyBy('name');
    }

    private function seedAcmeStudio(User $hadrien, User $alice, User $marco, User $sofia): void
    {
        $workspace = CreateWorkspace::run($hadrien, ['name' => 'Acme Studio']);

        foreach ([$alice, $marco, $sofia] as $member) {
            $workspace->memberships()->create([
                'user_id' => $member->getKey(),
                'role' => WorkspaceRole::Member->value,
            ]);
        }

        // Slot "In Review" between "In Progress" and "Done".
        $statuses = $this->statusesFor($workspace);
        $statuses['Done']->update(['position' => 3]);
        $statuses['Cancelled']->update(['position' => 4]);
        $statuses->put('In Review', Status::create([
            'workspace_id' => $workspace->id,
            'name' => 'In Review',
            'category' => StatusCategory::InProgress,
            'position' => 2,
        ]));

        $labels = collect([
            ['name' => 'Bug', 'color' => 'red'],
            ['name' => 'Feature', 'color' => 'blue'],
            ['name' => 'Documentation', 'color' => 'amber'],
            ['name' => 'Performance', 'color' => 'purple'],
            ['name' => 'UX', 'color' => 'pink'],
            ['name' => 'Tech Debt', 'color' => 'gray'],
        ])->map(fn (array $label): Label => Label::create([
            'workspace_id' => $workspace->id,
            'name' => $label['name'],
            'color' => $label['color'],
        ]))->keyBy('name');

        $website = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Website Redesign',
            'description' => 'Full overhaul of the public website: new design system, refreshed copy and a faster front-end.',
            'target_date' => now()->addDays(45),
        ]);

        $mobile = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Mobile App',
            'description' => 'Cross-platform mobile app for reading saved articles offline.',
            'target_date' => now()->addDays(90),
        ]);

        Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Q1 Marketing Site',
            'description' => 'Campaign landing pages for the Q1 launch. Closed after the rebrand pivot.',
            'target_date' => now()->subDays(30),
            'archived_at' => now()->subDays(20),
        ]);

        // --- Website Redesign -------------------------------------------------

        $hero = $this->createIssue($workspace, $website, $statuses['In Progress'], [
            'title' => 'Redesign homepage',
            'description' => 'New hero, social proof section and a clearer call to action. Epic tracking — work is split into subtasks.',
            'priority' => Priority::High,
            'due_date' => now()->addDays(10),
            'estimated_hours' => 16,
            'assignees' => [$alice, $marco],
        ]);

        $this->createIssue($workspace, $website, $statuses['Done'], [
            'title' => 'Write hero copy',
            'priority' => Priority::Low,
            'estimated_hours' => 2,
            'parent' => $hero,
            'assignees' => [$sofia],
        ]);

        $this->createIssue($workspace, $website, $statuses['Done'], [
            'title' => 'Design hero illustration',
            'estimated_hours' => 6,
            'parent' => $hero,
            'assignees' => [$alice],
        ]);

        $heroImplementation = $this->createIssue($workspace, $website, $statuses['In Review'], [
            'title' => 'Implement hero section',
            'description' => 'Responsive implementation on top of the new design tokens.',
            'priority' => Priority::High,
            'estimated_hours' => 8,
            'parent' => $hero,
            'labels' => [$labels['Feature']],
            'assignees' => [$marco],
        ]);

        $navBug = $this->createIssue($workspace, $website, $statuses['In Progress'], [
            'title' => 'Fix navigation overlap on mobile',
            'description' => 'The sticky nav overlaps the dropdown menu below 640px when the on-screen keyboard is open.',
            'priority' => Priority::Urgent,
            'due_date' => now()->subDays(2),
            'estimated_hours' => 3,
            'labels' => [$labels['Bug']],
            'assignees' => [$marco],
        ]);

        $this->createIssue($workspace, $website, $statuses['Backlog'], [
            'title' => 'Migrate blog to the new design system',
            'description' => 'Port the remaining blog templates to the shared component library.',
            'priority' => Priority::Low,
            'estimated_hours' => 12,
        ]);

        $this->createIssue($workspace, $website, $statuses['Backlog'], [
            'title' => 'Add dark mode toggle',
            'priority' => Priority::Low,
            'labels' => [$labels['Feature'], $labels['UX']],
        ]);

        $this->createIssue($workspace, $website, $statuses['Backlog'], [
            'title' => 'Optimize hero image loading',
            'description' => 'Serve AVIF with a JPEG fallback and lazy-load below the fold.',
            'priority' => Priority::High,
            'estimated_hours' => 4,
            'labels' => [$labels['Performance']],
        ]);

        $this->createIssue($workspace, $website, $statuses['Backlog'], [
            'title' => 'Document accessibility guidelines',
            'labels' => [$labels['Documentation']],
        ]);

        $this->createIssue($workspace, $website, $statuses['Cancelled'], [
            'title' => 'Replace legacy jQuery slider',
            'description' => 'Superseded by the redesigned homepage — no longer needed.',
        ]);

        $ciPipeline = $this->createIssue($workspace, $website, $statuses['Done'], [
            'title' => 'Set up CI pipeline',
            'description' => 'Run tests and static analysis on every pull request.',
            'estimated_hours' => 4,
            'assignees' => [$hadrien],
        ]);

        // --- Mobile App --------------------------------------------------------

        $pushNotifications = $this->createIssue($workspace, $mobile, $statuses['In Progress'], [
            'title' => 'Push notifications',
            'description' => 'Register devices, receive and display push notifications, manage per-category preferences.',
            'priority' => Priority::High,
            'due_date' => now()->addDays(21),
            'estimated_hours' => 12,
            'assignees' => [$sofia],
        ]);

        $this->createIssue($workspace, $mobile, $statuses['In Review'], [
            'title' => 'Design notification preferences screen',
            'estimated_hours' => 4,
            'parent' => $pushNotifications,
            'labels' => [$labels['UX']],
            'assignees' => [$sofia],
        ]);

        $tokenRefresh = $this->createIssue($workspace, $mobile, $statuses['In Progress'], [
            'title' => 'Handle FCM token refresh',
            'priority' => Priority::Urgent,
            'estimated_hours' => 3,
            'parent' => $pushNotifications,
            'labels' => [$labels['Bug']],
            'assignees' => [$marco],
        ]);

        $androidCrash = $this->createIssue($workspace, $mobile, $statuses['Done'], [
            'title' => 'Crash on Android 15 when camera permission is denied',
            'description' => 'The permission callback is invoked with a null result on Android 15 and blows up the reader scanner.',
            'priority' => Priority::Urgent,
            'estimated_hours' => 2,
            'labels' => [$labels['Bug']],
            'assignees' => [$sofia],
        ]);

        $this->createIssue($workspace, $mobile, $statuses['Backlog'], [
            'title' => 'Offline mode for saved articles',
            'description' => 'Cache saved articles and sync reading progress when back online.',
            'priority' => Priority::High,
            'estimated_hours' => 20,
            'labels' => [$labels['Feature']],
        ]);

        $this->createIssue($workspace, $mobile, $statuses['Backlog'], [
            'title' => 'Refresh App Store screenshots',
            'priority' => Priority::Low,
            'due_date' => now()->addDays(30),
        ]);

        // --- Comments ----------------------------------------------------------

        $this->addComment($navBug, $marco, 'Reproduced on iOS Safari and Android Chrome. It happens when the menu is open and the keyboard slides up.');
        $this->addComment($navBug, $alice, 'The breakpoint at 768px looks wrong — the nav should collapse below 640px.');
        $this->addComment($navBug, $hadrien, 'Let\'s ship a hotfix this week, marketing launches Monday.');
        $this->addComment($heroImplementation, $alice, 'First pass looks great. Can we try the headline 10% larger?');
        $this->addComment($androidCrash, $sofia, 'Fixed by guarding the permission callback — regression test added.');

        // --- Time entries ------------------------------------------------------

        $this->logTime($heroImplementation, $marco, 2, 4, 'Component and responsive styles');
        $this->logTime($heroImplementation, $marco, 1, 2.5, 'Review fixes');
        $this->logTime($navBug, $marco, 1, 1.5, 'Reproducing and root cause');
        $this->logTime($tokenRefresh, $marco, 1, 1, 'Investigation');
        $this->logTime($androidCrash, $sofia, 4, 2.5, 'Fix and regression test');
        $this->logTime($ciPipeline, $hadrien, 9, 3, 'Workflow setup and cache tuning');

        // --- Templates & invitations --------------------------------------------

        $bugReport = IssueTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bug Report',
            'description' => "## Steps to reproduce\n1. \n\n## Expected result\n\n## Actual result",
            'priority' => Priority::High,
        ]);
        $bugReport->labels()->attach($labels['Bug']);

        $featureRequest = IssueTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Feature Request',
            'description' => "## Problem\n\n## Proposed solution",
        ]);
        $featureRequest->labels()->attach($labels['Feature']);

        Invitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'chloe@taskr.dev',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        Invitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'lucas@taskr.dev',
            'token' => Str::random(64),
            'expires_at' => now()->subDays(3),
        ]);
    }

    private function seedPersonal(User $hadrien): void
    {
        $workspace = CreateWorkspace::run($hadrien, ['name' => 'Perso']);
        $statuses = $this->statusesFor($workspace);

        $apartment = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Apartment',
            'description' => 'Home improvement backlog.',
        ]);

        $this->createIssue($workspace, $apartment, $statuses['In Progress'], [
            'title' => 'Repaint the living room',
            'estimated_hours' => 8,
        ]);

        $this->createIssue($workspace, $apartment, $statuses['Backlog'], [
            'title' => 'Fix the leaking kitchen faucet',
            'priority' => Priority::High,
            'due_date' => now()->subDay(),
        ]);

        $this->createIssue($workspace, $apartment, $statuses['Done'], [
            'title' => 'Assemble the bookshelf',
            'estimated_hours' => 3,
        ]);
    }

    /**
     * @param  array{
     *     title: string,
     *     description?: string|null,
     *     priority?: Priority|null,
     *     due_date?: Carbon|null,
     *     estimated_hours?: int|float|null,
     *     parent?: Issue|null,
     *     labels?: array<int, Label>,
     *     assignees?: array<int, User>,
     * }  $data
     */
    private function createIssue(Workspace $workspace, Project $project, Status $status, array $data): Issue
    {
        $issue = Issue::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'parent_id' => ($data['parent'] ?? null)?->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status_id' => $status->id,
            'priority' => $data['priority'] ?? Priority::None,
            'due_date' => $data['due_date'] ?? null,
            'estimated_hours' => $data['estimated_hours'] ?? null,
        ]);

        if (! empty($data['labels'])) {
            $issue->labels()->attach($data['labels']);
        }

        if (! empty($data['assignees'])) {
            $issue->assignees()->attach($data['assignees']);
        }

        return $issue;
    }

    private function addComment(Issue $issue, User $author, string $body): Comment
    {
        return Comment::create([
            'issue_id' => $issue->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }

    private function logTime(Issue $issue, User $author, int $daysAgo, float $hours, string $note): TimeEntry
    {
        return TimeEntry::create([
            'workspace_id' => $issue->workspace_id,
            'issue_id' => $issue->id,
            'author_id' => $author->id,
            'worked_at' => now()->subDays($daysAgo),
            'hours' => $hours,
            'note' => $note,
        ]);
    }
}
