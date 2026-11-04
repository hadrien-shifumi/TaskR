<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExportUiTest extends TestCase
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

    public function test_issues_page_offers_the_export_action_with_filtered_count(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Un']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Deux']);

        // The page is client-rendered (no SSR): the export hooks live in the
        // built Issues/Index bundle, so assert them on the compiled source.
        $source = file_get_contents(resource_path('js/Pages/Issues/Index.tsx'));

        $this->assertStringContainsString('data-test="issues-export"', $source);
        $this->assertStringContainsString('data-test="issues-export-csv"', $source);
        $this->assertStringContainsString('data-test="issues-export-json"', $source);
        $this->assertStringContainsString('data-test="export-error"', $source);
        $this->assertStringContainsString("route('issues.export')", $source);

        // The trigger carries the filtered count: the server-provided total in
        // list view, the client-side board sum in kanban.
        $this->assertStringContainsString("t('issuesPage.export.button', { count: filteredTotal })", $source);

        // List view: the count the button shows is the filtered total prop.
        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('total', 2)
                ->where('totalUnfiltered', 2));
    }

    public function test_kanban_view_feeds_the_export_count_from_board_totals(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Un']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Deux']);

        // The kanban carries no `total` prop: the export count is summed
        // client-side from the column counts, which must therefore be exact.
        $this->actingAs($owner)
            ->get('/issues?view=kanban')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('total')
                ->where('projectBoards.0.columns.0.issuesCount', 2));
    }

    public function test_project_health_page_offers_a_time_entries_export_dialog(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        // Client-rendered page: assert the export dialog hooks on the source.
        $source = file_get_contents(resource_path('js/Pages/Projects/Health.tsx'));

        $this->assertStringContainsString('data-test="time-export"', $source);
        $this->assertStringContainsString('data-test="time-export-format"', $source);
        $this->assertStringContainsString('data-test="time-export-after"', $source);
        $this->assertStringContainsString('data-test="time-export-before"', $source);
        $this->assertStringContainsString('data-test="time-export-submit"', $source);
        $this->assertStringContainsString('data-test="export-error"', $source);
        $this->assertStringContainsString("route('time-entries.export')", $source);

        // The dialog sends the period bounds under the backend names.
        $this->assertStringContainsString('worked_after:', $source);
        $this->assertStringContainsString('worked_before:', $source);

        // The dialog is fed by the page props alone (the project id).
        $this->actingAs($owner)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.id', $project->id));
    }

    public function test_the_download_helper_serves_files_and_surfaces_server_refusals(): void
    {
        // The download helper is the one file-delivery channel of the app:
        // Inertia visits cannot deliver files, so the export routes are
        // fetched directly, blob-handed to the browser, and the refusals
        // (beyond the row limit) are read from the 422 JSON body.
        $path = resource_path('js/lib/download-export.ts');
        $this->assertFileExists($path);

        $source = file_get_contents($path);

        $this->assertStringContainsString('URLSearchParams', $source);
        $this->assertStringContainsString('createObjectURL', $source);
        $this->assertStringContainsString('content-disposition', $source);
        $this->assertStringContainsString('errors', $source);

        // The refusal must be readable: a plain fetch declares
        // `Accept: */*`, which makes the server answer a redirect the
        // client cannot read — the JSON accept pins the 422 contract.
        $this->assertStringContainsString('application/json', $source);
    }

    public function test_the_export_texts_exist_in_french_and_english(): void
    {
        $needles = [
            'fr' => [
                'button: "Exporter ({{count}})"',
                'failed: "L\'export a échoué. Réessayez."',
                'button: "Exporter le temps"',
                'workedAfter: "Du"',
                'workedBefore: "Au"',
            ],
            'en' => [
                'button: "Export ({{count}})"',
                'failed: "The export failed. Please try again."',
                'button: "Export time"',
                'workedAfter: "From"',
                'workedBefore: "To"',
            ],
        ];

        foreach ($needles as $locale => $localeNeedles) {
            $contents = file_get_contents(resource_path("js/i18n/{$locale}.ts"));

            foreach ($localeNeedles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $contents,
                    "The export text {$needle} is missing from {$locale}.ts.",
                );
            }
        }
    }
}
