<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimeEntryExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace with one project, its owner and an issue.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        return [$owner, $workspace, $project, $issue];
    }

    /**
     * A guest scoped to one project, with time entries on both sides.
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

        CreateTimeEntry::run($inScopeIssue, $owner, ['hours' => 1.5, 'worked_at' => CarbonImmutable::today()->toDateString()]);
        CreateTimeEntry::run($outOfScopeIssue, $owner, ['hours' => 2.5, 'worked_at' => CarbonImmutable::today()->toDateString()]);

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
     * The exported entry identifiers, in row order.
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
        $this->get(route('time-entries.export'))->assertRedirect(route('login'));
    }

    public function test_export_csv_carries_the_expected_columns(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        SetIssueEstimate::run($issue, 3.5);
        $first = CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'worked_at' => '2026-10-14', 'note' => 'setup CI']);
        $second = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => '2026-10-15']);

        // A second ticket without estimate: its total is its own hours,
        // the estimate cell stays empty.
        $bare = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans estimation']);
        $bareEntry = CreateTimeEntry::run($bare, $owner, ['hours' => 2.0, 'worked_at' => '2026-10-16']);

        $response = $this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => '2026-10-01',
            'worked_before' => '2026-10-31',
        ]));

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'saisies-temps-'.now()->format('Y-m-d').'.csv',
            (string) $response->headers->get('Content-Disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'The CSV opens with a UTF-8 BOM for spreadsheets.');

        $rows = $this->parseCsv($content);
        $this->assertSame([
            'Identifiant', 'Ticket', 'Titre du ticket', 'Projet', 'Auteur',
            'Date du travail', 'Heures', 'Note',
            'Total consommé par ticket (heures)', 'Estimation (heures)',
        ], $rows[0]);

        $byId = collect($rows)->slice(1)->keyBy(0);

        $firstRow = $byId[$first->id];
        $this->assertSame($issue->id, $firstRow[1]);
        $this->assertSame('Page de profil', $firstRow[2]);
        $this->assertSame('Site vitrine', $firstRow[3]);
        $this->assertSame($owner->name, $firstRow[4]);
        $this->assertSame('2026-10-14', $firstRow[5]);
        $this->assertSame('1.50', $firstRow[6]);
        $this->assertSame('setup CI', $firstRow[7]);
        $this->assertSame('2.50', $firstRow[8]);
        $this->assertSame('3.50', $firstRow[9]);

        // The second entry of the same ticket repeats the same totals.
        $secondRow = $byId[$second->id];
        $this->assertSame('1.00', $secondRow[6]);
        $this->assertSame('', $secondRow[7]);
        $this->assertSame('2.50', $secondRow[8]);
        $this->assertSame('3.50', $secondRow[9]);

        $bareRow = $byId[$bareEntry->id];
        $this->assertSame($bare->id, $bareRow[1]);
        $this->assertSame('Sans estimation', $bareRow[2]);
        $this->assertSame('2.00', $bareRow[6]);
        $this->assertSame('2.00', $bareRow[8]);
        $this->assertSame('', $bareRow[9]);
    }

    public function test_export_csv_escapes_special_characters(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $entry = CreateTimeEntry::run($issue, $owner, [
            'hours' => 1.0,
            'worked_at' => CarbonImmutable::today()->toDateString(),
            'note' => 'Facturation, "octobre"; à valider',
        ]);

        $rows = $this->parseCsv($this->actingAs($owner)->get(route('time-entries.export'))->streamedContent());
        $byId = collect($rows)->slice(1)->keyBy(0);

        $this->assertSame('Facturation, "octobre"; à valider', $byId[$entry->id][7]);
    }

    public function test_export_json_carries_the_expected_fields(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        SetIssueEstimate::run($issue, 3.5);
        $first = CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'worked_at' => '2026-10-14', 'note' => 'setup CI']);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => '2026-10-15']);

        $bare = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans estimation']);
        $bareEntry = CreateTimeEntry::run($bare, $owner, ['hours' => 2.0, 'worked_at' => '2026-10-16']);

        $response = $this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => '2026-10-01',
            'worked_before' => '2026-10-31',
            'format' => 'json',
        ]));

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'saisies-temps-'.now()->format('Y-m-d').'.json',
            (string) $response->headers->get('Content-Disposition'),
        );

        $rows = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $byId = collect($rows)->keyBy('id');

        $firstRow = $byId[$first->id];
        $this->assertSame($issue->id, $firstRow['ticketId']);
        $this->assertSame('Page de profil', $firstRow['ticketTitle']);
        $this->assertSame('Site vitrine', $firstRow['project']);
        $this->assertSame($owner->name, $firstRow['author']);
        $this->assertSame('2026-10-14', $firstRow['workedAt']);
        $this->assertSame(1.5, $firstRow['hours']);
        $this->assertSame('setup CI', $firstRow['note']);
        $this->assertSame(2.5, $firstRow['ticketLoggedHours']);
        $this->assertSame(3.5, $firstRow['ticketEstimatedHours']);

        $bareRow = $byId[$bareEntry->id];
        $this->assertSame($bare->id, $bareRow['ticketId']);
        $this->assertSame(2.0, $bareRow['ticketLoggedHours']);
        $this->assertNull($bareRow['ticketEstimatedHours']);
    }

    public function test_export_defaults_to_the_current_month(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today();
        $inMonth = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->addDays(14)->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonths(2)->toDateString()]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export'))->streamedContent());

        $this->assertSame([$inMonth->id], $ids);
    }

    public function test_export_follows_an_explicit_period(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today();
        $inWindow = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonths(3)->subDay()->toDateString()]);

        // A window covering the first of the previous month.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => $today->startOfMonth()->subMonths(2)->toDateString(),
            'worked_before' => $today->startOfMonth()->subMonth()->addDays(14)->toDateString(),
        ]))->streamedContent());

        $this->assertSame([$inWindow->id], $ids);
    }

    public function test_export_includes_entries_worked_on_the_period_upper_bound_day(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        // An entry worked today, exported with today as the upper bound:
        // the day the user asks for must be part of the window — sqlite
        // stores date casts with a time part, so a bare string comparison
        // against a `Y-m-d` bound silently excludes the bound day itself.
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 2.0, 'worked_at' => CarbonImmutable::today()->toDateString()]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'worked_before' => CarbonImmutable::today()->toDateString(),
        ]))->streamedContent());

        $this->assertSame([$entry->id], $ids);
    }

    public function test_export_invalid_period_falls_back_to_the_current_month(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today();
        $inMonth = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->addDays(14)->toDateString()]);

        // Overflowing and non-date bounds: silently ignored, the export
        // stays bounded on the current month instead of failing.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => '2026-13-45',
            'worked_before' => 'not-a-date',
        ]))->streamedContent());

        $this->assertSame([$inMonth->id], $ids);
    }

    public function test_export_inverted_period_falls_back_to_the_current_month(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today();
        $inMonth = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->addDays(14)->toDateString()]);

        // End before start: the window is incoherent, the export falls
        // back to the whole current month.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => $today->startOfMonth()->subMonth()->addDays(14)->toDateString(),
            'worked_before' => $today->startOfMonth()->subMonths(2)->toDateString(),
        ]))->streamedContent());

        $this->assertSame([$inMonth->id], $ids);
    }

    public function test_export_partial_period_keeps_the_given_bound(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today();
        $todayEntry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);
        $prevMonth = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->toDateString()]);
        $windowStart = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonths(2)->toDateString()]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonths(3)->subDay()->toDateString()]);

        // Only the start is given: it is respected, the end falls back
        // to the current month's last day. The window start itself is
        // included, older entries are not.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'worked_after' => $today->startOfMonth()->subMonths(2)->toDateString(),
        ]))->streamedContent());

        // Newest first: the export order is stable.
        $this->assertSame([$todayEntry->id, $prevMonth->id, $windowStart->id], $ids);
    }

    public function test_export_follows_the_project_filter(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $alphaIssue = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Alpha 1']);
        $betaIssue = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Beta 1']);

        $today = CarbonImmutable::today()->toDateString();
        $alphaEntry = CreateTimeEntry::run($alphaIssue, $owner, ['hours' => 1.0, 'worked_at' => $today]);
        CreateTimeEntry::run($betaIssue, $owner, ['hours' => 1.0, 'worked_at' => $today]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', ['project' => $alpha->id]))->streamedContent());

        $this->assertSame([$alphaEntry->id], $ids);

        // An unknown project id is a filter like any other: it matches
        // nothing, it never fails and never leaks anything.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', ['project' => (string) Str::ulid()]))->streamedContent());

        $this->assertSame([], $ids);
    }

    public function test_export_follows_the_author_filter(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => app(WorkspaceContext::class)->getOrFail()->id,
            'role' => 'member',
        ]);

        $today = CarbonImmutable::today()->toDateString();
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today]);
        $memberEntry = CreateTimeEntry::run($issue, $member, ['hours' => 2.0, 'worked_at' => $today]);

        // The author filter is open to every reader: the owner exports
        // the member's entries.
        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', ['author' => $member->id]))->streamedContent());

        $this->assertSame([$memberEntry->id], $ids);
    }

    public function test_export_combines_the_project_author_and_period_filters(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $alphaIssue = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Alpha 1']);
        $betaIssue = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Beta 1']);

        $today = CarbonImmutable::today();
        $needle = CreateTimeEntry::run($alphaIssue, $member, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->toDateString()]);

        // Right project and author, wrong author / project / period.
        CreateTimeEntry::run($alphaIssue, $owner, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->toDateString()]);
        CreateTimeEntry::run($betaIssue, $member, ['hours' => 1.0, 'worked_at' => $today->startOfMonth()->subMonth()->toDateString()]);
        CreateTimeEntry::run($alphaIssue, $member, ['hours' => 1.0, 'worked_at' => $today->toDateString()]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export', [
            'project' => $alpha->id,
            'author' => $member->id,
            'worked_after' => $today->startOfMonth()->subMonths(2)->toDateString(),
            'worked_before' => $today->startOfMonth()->subDay()->toDateString(),
        ]))->streamedContent());

        $this->assertSame([$needle->id], $ids);
    }

    public function test_export_respects_the_guest_perimeter(): void
    {
        [$owner, , , $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $inScopeEntry = TimeEntry::where('issue_id', $inScopeIssue->id)->firstOrFail();

        $response = $this->actingAs($guest)->get(route('time-entries.export'));

        $response->assertOk();
        $ids = $this->exportedIds($response->streamedContent());

        $this->assertSame([$inScopeEntry->id], $ids);
        $this->assertNotContains(TimeEntry::where('issue_id', $outOfScopeIssue->id)->firstOrFail()->id, $ids);

        // Same for the JSON format: the out-of-perimeter entry is invisible.
        $rows = json_decode($this->actingAs($guest)->get(route('time-entries.export', ['format' => 'json']))->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([$inScopeEntry->id], array_column($rows, 'id'));
    }

    public function test_export_never_leaks_another_workspace(): void
    {
        [$owner, $workspace, , $issue] = $this->setupWorkspace();

        $ownEntry = CreateTimeEntry::run($issue, $owner, ['hours' => 2.0, 'worked_at' => CarbonImmutable::today()->toDateString()]);

        // A second workspace with its own time entries.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Other project']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Other issue']);
        CreateTimeEntry::run($otherIssue, $otherOwner, ['hours' => 3.0, 'worked_at' => CarbonImmutable::today()->toDateString()]);

        // Back to the first workspace context.
        app(WorkspaceContext::class)->set($workspace);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export'))->streamedContent());

        $this->assertSame([$ownEntry->id], $ids);
    }

    public function test_export_includes_entries_of_archived_issues(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        // The billing export covers every entry of the period: a
        // completed then archived ticket is still billable.
        $issue->update(['archived_at' => now()]);
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => CarbonImmutable::today()->toDateString()]);

        $ids = $this->exportedIds($this->actingAs($owner)->get(route('time-entries.export'))->streamedContent());

        $this->assertSame([$entry->id], $ids);
    }

    public function test_export_refuses_above_max_rows_with_a_filtering_message(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        // The shipped policy: the export is refused beyond 50 000 rows.
        $this->assertSame(50_000, (int) config('taskr.export_max_rows'));

        config(['taskr.export_max_rows' => 2]);

        $today = CarbonImmutable::today()->toDateString();
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today]);

        // The refusal message follows the requesting user's language
        // preference, like every message: the test pins it instead of
        // depending on the app default.
        $owner->update(['locale' => 'fr']);

        $this->actingAs($owner)
            ->getJson(route('time-entries.export'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['time_entries']);

        // The message is dedicated to time entries — not the tickets
        // one — and carries the configured limit with an invitation to
        // narrow the filters.
        $message = $this->actingAs($owner)->getJson(route('time-entries.export'))->json('errors.time_entries.0');
        $this->assertStringContainsString(number_format((int) config('taskr.export_max_rows'), 0, ',', ' '), $message);
        $this->assertStringContainsString('saisies de temps', $message);
        $this->assertStringContainsString('Affinez les filtres', $message);
    }

    public function test_export_keeps_a_constant_query_count_regardless_of_volume(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();

        $today = CarbonImmutable::today()->toDateString();
        foreach (['a', 'b', 'c'] as $index) {
            CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today, 'note' => "entry {$index}"]);
        }

        // Warm-up request: the workspace middleware remembers the
        // current workspace on the very first request of the session
        // only — the measured runs must be comparable.
        $this->actingAs($owner)->get(route('time-entries.export'))->streamedContent();

        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('time-entries.export'))->streamedContent();
        $smallRun = count(DB::getQueryLog());

        foreach (['d', 'e', 'f', 'g'] as $index) {
            CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'worked_at' => $today, 'note' => "entry {$index}"]);
        }

        DB::flushQueryLog();
        $this->actingAs($owner)->get(route('time-entries.export'))->streamedContent();
        $biggerRun = count(DB::getQueryLog());

        // The log really captured the export, and doubling the volume
        // must not add a single query: no per-row lookup (ticket,
        // project, author, per-ticket totals) may ride the export.
        $this->assertGreaterThan(0, $smallRun);
        $this->assertSame($smallRun, $biggerRun);
    }

    public function test_export_serves_the_file_to_a_json_accepting_client(): void
    {
        [$owner, , , $issue] = $this->setupWorkspace();
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.5]);

        // The UI download channel fetches the export with a JSON accept
        // header: the file must be served as-is — a JSON-expecting client
        // must never trip a response hook or see the file reinterpreted.
        $response = $this->actingAs($owner)->getJson(route('time-entries.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'saisies-temps-'.now()->format('Y-m-d').'.csv',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent(), 'The CSV opens with a UTF-8 BOM for spreadsheets.');
    }
}
