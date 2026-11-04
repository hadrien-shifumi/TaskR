<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueDependencies;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace with one project and its owner.
     *
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }

    /**
     * A guest scoped to one project, with issues on both sides.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue, 4: Issue, 5: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $inScope, $inScopeIssue, $outOfScopeIssue, $guest];
    }

    /**
     * Parse a CSV body (BOM stripped) into rows of cells.
     *
     * @return list<list<string>>
     */
    private function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $content) ?: [],
            fn (string $line): bool => $line !== '',
        ));

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', '\\'), $lines);
    }

    /**
     * The exported identifiers, in row order.
     *
     * @return list<string>
     */
    private function exportedIds(string $content): array
    {
        return array_map(
            fn (array $row): string => $row[0],
            array_slice($this->parseCsv($content), 1),
        );
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('issues.export'))->assertRedirect(route('login'));
    }

    public function test_export_csv_carries_the_expected_columns(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $label = CreateLabel::run($workspace, ['name' => 'front']);
        $status = $workspace->statuses()->where('category', 'in_progress')->firstOrFail();
        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'EPIC site', 'created_by' => $owner->id]);

        $child = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Page de profil',
            'status_id' => $status->id,
            'priority' => 'high',
            'due_date' => '2026-12-01',
            'label_ids' => [$label->id],
            'assignees' => [$owner->id],
            'created_by' => $owner->id,
        ]);
        SetIssueParent::run($child, $parent->id);
        SetIssueEstimate::run($child, 3.5);
        CreateTimeEntry::run($child, $owner, null, 1.5);
        CreateTimeEntry::run($child, $owner, null, 1.0);

        $response = $this->actingAs($owner)->get(route('issues.export'));

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'The CSV opens with a UTF-8 BOM for spreadsheets.');

        $rows = $this->parseCsv($content);
        $this->assertSame([
            'Identifiant', 'Titre', 'Projet', 'Statut', 'Catégorie', 'Priorité',
            'Labels', 'Assignés', 'Échéance', 'Estimation (heures)',
            'Temps consommé (heures)', 'Parent', 'Créateur', 'Créé le', 'Mis à jour le',
        ], $rows[0]);

        $byId = collect($rows)->slice(1)->keyBy(0);

        $childRow = $byId[$child->id];
        $this->assertSame('Page de profil', $childRow[1]);
        $this->assertSame('Site vitrine', $childRow[2]);
        $this->assertSame($status->name, $childRow[3]);
        $this->assertSame('in_progress', $childRow[4]);
        $this->assertSame('high', $childRow[5]);
        $this->assertSame('front', $childRow[6]);
        $this->assertSame($owner->name, $childRow[7]);
        $this->assertSame('2026-12-01', $childRow[8]);
        $this->assertSame('3.50', $childRow[9]);
        $this->assertSame('2.50', $childRow[10]);
        $this->assertSame('EPIC site', $childRow[11]);
        $this->assertSame($owner->name, $childRow[12]);
        $this->assertSame($child->created_at->format('Y-m-d H:i:s'), $childRow[13]);
        $this->assertSame($child->updated_at->format('Y-m-d H:i:s'), $childRow[14]);

        // The parent carries no labels, assignees, due date, estimate or
        // parent of its own: the cells stay empty, the consumed time is 0.
        $parentRow = $byId[$parent->id];
        $this->assertSame('EPIC site', $parentRow[1]);
        $this->assertSame('', $parentRow[6]);
        $this->assertSame('', $parentRow[7]);
        $this->assertSame('', $parentRow[8]);
        $this->assertSame('', $parentRow[9]);
        $this->assertSame('0.00', $parentRow[10]);
        $this->assertSame('', $parentRow[11]);
        $this->assertSame($owner->name, $parentRow[12]);
    }

    public function test_export_csv_escapes_special_characters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $tricky = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Ticket, "complexe"; à lire',
        ]);

        $rows = $this->parseCsv($this->actingAs($owner)->get(route('issues.export'))->streamedContent());
        $byId = collect($rows)->slice(1)->keyBy(0);

        $this->assertSame('Ticket, "complexe"; à lire', $byId[$tricky->id][1]);
    }

    public function test_export_json_carries_the_expected_fields(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $label = CreateLabel::run($workspace, ['name' => 'front']);
        $status = $workspace->statuses()->where('category', 'in_progress')->firstOrFail();
        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'EPIC site', 'created_by' => $owner->id]);

        $child = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Page de profil',
            'status_id' => $status->id,
            'priority' => 'high',
            'due_date' => '2026-12-01',
            'label_ids' => [$label->id],
            'assignees' => [$owner->id],
            'created_by' => $owner->id,
        ]);
        SetIssueParent::run($child, $parent->id);
        SetIssueEstimate::run($child, 3.5);
        CreateTimeEntry::run($child, $owner, null, 1.5);

        $response = $this->actingAs($owner)->get(route('issues.export', ['format' => 'json']));

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $rows = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $byId = collect($rows)->keyBy('id');

        $childRow = $byId[$child->id];
        $this->assertSame('Page de profil', $childRow['title']);
        $this->assertSame('Site vitrine', $childRow['project']);
        $this->assertSame($status->name, $childRow['status']);
        $this->assertSame('in_progress', $childRow['category']);
        $this->assertSame('high', $childRow['priority']);
        $this->assertSame(['front'], $childRow['labels']);
        $this->assertSame([$owner->name], $childRow['assignees']);
        $this->assertSame('2026-12-01', $childRow['dueDate']);
        $this->assertSame(3.5, $childRow['estimatedHours']);
        $this->assertSame(1.5, $childRow['loggedHours']);
        $this->assertSame('EPIC site', $childRow['parent']);
        $this->assertSame($owner->name, $childRow['creator']);
        $this->assertSame($child->created_at->toISOString(), $childRow['createdAt']);
        $this->assertSame($child->updated_at->toISOString(), $childRow['updatedAt']);

        // The parent carries no extras: the optional fields stay null.
        $parentRow = $byId[$parent->id];
        $this->assertSame([], $parentRow['labels']);
        $this->assertSame([], $parentRow['assignees']);
        $this->assertNull($parentRow['dueDate']);
        $this->assertNull($parentRow['estimatedHours']);
        $this->assertSame(0.0, $parentRow['loggedHours']);
        $this->assertNull($parentRow['parent']);
        $this->assertSame($owner->name, $parentRow['creator']);
    }

    public function test_export_excludes_archived_issues_by_default(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $active = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archivée']);
        $archived->update(['archived_at' => now()]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export'))->streamedContent());

        $this->assertSame([$active->id], $ids);
    }

    public function test_export_follows_the_project_filter(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $alphaIssue = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Alpha 1']);
        CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Beta 1']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['project' => $alpha->id]))->streamedContent());

        $this->assertSame([$alphaIssue->id], $ids);
    }

    public function test_export_follows_the_status_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $backlog = $workspace->statuses()->where('category', 'backlog')->firstOrFail();
        $doing = $workspace->statuses()->where('category', 'in_progress')->firstOrFail();

        $backlogIssue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A', 'status_id' => $backlog->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B', 'status_id' => $doing->id]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['status' => $backlog->id]))->streamedContent());

        $this->assertSame([$backlogIssue->id], $ids);
    }

    public function test_export_follows_the_category_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $done = $workspace->statuses()->where('category', 'done')->firstOrFail();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $doneIssue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B', 'status_id' => $done->id]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['category' => 'done']))->streamedContent());

        $this->assertSame([$doneIssue->id], $ids);
    }

    public function test_export_follows_the_priority_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $urgent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B', 'priority' => 'urgent']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['priority' => 'urgent']))->streamedContent());

        $this->assertSame([$urgent->id], $ids);
    }

    public function test_export_follows_the_label_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $back = CreateLabel::run($workspace, ['name' => 'back']);

        $frontIssue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A', 'label_ids' => [$front->id]]);
        $bothIssue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B', 'label_ids' => [$front->id, $back->id]]);
        $backIssue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C', 'label_ids' => [$back->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'D']);

        // A single label: a ticket matches as soon as it carries it.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['label' => $front->id]))->streamedContent());
        $this->assertEqualsCanonicalizing([$frontIssue->id, $bothIssue->id], $ids);

        // Several labels: still an OR match, like the page's multi-select —
        // every ticket carrying at least one selected label is exported.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['label' => [$front->id, $back->id]]))->streamedContent());
        $this->assertEqualsCanonicalizing([$frontIssue->id, $bothIssue->id, $backIssue->id], $ids);
    }

    public function test_export_follows_the_assignee_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $assigned = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A', 'assignees' => [$owner->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['assignee' => $owner->id]))->streamedContent());

        $this->assertSame([$assigned->id], $ids);
    }

    public function test_export_follows_the_search_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $needle = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Autre chose']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['q' => 'profil']))->streamedContent());
        $this->assertSame([$needle->id], $ids);

        // A pasted identifier prefix finds the ticket too, like the page. The
        // prefix encodes the creation timestamp: tickets born in the same
        // millisecond share it, so the needle is asserted to be found among
        // the results, never to be the only one.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['q' => substr($needle->id, 0, 7)]))->streamedContent());
        $this->assertContains($needle->id, $ids);
    }

    public function test_export_follows_the_due_date_filters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Trop tôt', 'due_date' => '2026-01-15']);
        $inWindow = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Dans la fenêtre', 'due_date' => '2026-06-15']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Trop tard', 'due_date' => '2026-12-15']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans échéance']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['due_after' => '2026-02-01', 'due_before' => '2026-07-01']))->streamedContent());

        $this->assertSame([$inWindow->id], $ids);
    }

    public function test_export_follows_the_overdue_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $late = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'En retard', 'due_date' => '2020-01-01']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'À temps', 'due_date' => '2099-01-01']);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['overdue' => '1']))->streamedContent());

        $this->assertSame([$late->id], $ids);
    }

    public function test_export_follows_the_blocked_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $blocker = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Bloqueur']);
        $blocked = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Bloquée']);
        SetIssueDependencies::run($blocked, [$blocker->id]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('issues.export', ['blocked' => '1']))->streamedContent());

        $this->assertSame([$blocked->id], $ids);
    }

    public function test_export_respects_the_guest_perimeter(): void
    {
        [$owner, $workspace, $inScope, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $response = $this->actingAs($guest)->get(route('issues.export'));

        $response->assertOk();
        $ids = $this->exportedIds($response->streamedContent());

        $this->assertSame([$inScopeIssue->id], $ids);
        $this->assertNotContains($outOfScopeIssue->id, $ids);

        // Same for the JSON format: the out-of-perimeter ticket is invisible.
        $rows = json_decode($this->actingAs($guest)->get(route('issues.export', ['format' => 'json']))->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([$inScopeIssue->id], array_column($rows, 'id'));
    }

    public function test_export_refuses_above_max_rows_with_a_filtering_message(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        // The shipped policy: the export is refused beyond 50 000 tickets.
        $this->assertSame(50_000, (int) config('taskr.export_max_rows'));

        config(['taskr.export_max_rows' => 2]);

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C']);

        // The refusal message follows the requesting user's language
        // preference, like every message: the test pins it instead of
        // depending on the app default.
        $owner->update(['locale' => 'fr']);

        $this->actingAs($owner)
            ->getJson(route('issues.export'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['issues']);

        // The message carries the configured limit — never a hard-coded
        // one — and invites to narrow the filters.
        $message = $this->actingAs($owner)->getJson(route('issues.export'))->json('errors.issues.0');
        $this->assertStringContainsString(number_format((int) config('taskr.export_max_rows'), 0, ',', ' '), $message);
        $this->assertStringContainsString('Affinez les filtres', $message);
    }

    public function test_export_keeps_a_constant_query_count_regardless_of_volume(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        foreach (['A', 'B', 'C'] as $title) {
            $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title]);
            CreateTimeEntry::run($issue, $owner, null, 1.0);
        }

        $this->actingAs($owner)->get(route('issues.export'))->streamedContent();
        $smallRun = count(DB::getQueryLog());

        foreach (['D', 'E', 'F', 'G'] as $title) {
            $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title]);
            CreateTimeEntry::run($issue, $owner, null, 1.0);
        }

        DB::flushQueryLog();
        $this->actingAs($owner)->get(route('issues.export'))->streamedContent();
        $biggerRun = count(DB::getQueryLog());

        // Doubling the volume must not add a single query: no per-row
        // lookup (logged time, creator, parent) may ride the export.
        $this->assertSame($smallRun, $biggerRun);
    }

    public function test_export_serves_the_file_to_a_json_accepting_client(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        // The UI download channel fetches the export with a JSON accept
        // header: the file must be served as-is — a JSON-expecting client
        // must never trip a response hook or see the file reinterpreted.
        $response = $this->actingAs($owner)->getJson(route('issues.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'tickets-'.now()->format('Y-m-d').'.csv',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent(), 'The CSV opens with a UTF-8 BOM for spreadsheets.');
    }
}
