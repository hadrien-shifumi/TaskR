<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class WorkspaceBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace and its owner, resolved as the current workspace.
     *
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(string $name = 'WS'): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => $name]);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    /**
     * A user with the given role on the workspace.
     */
    private function memberOf(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Write the downloaded body to a temp file and open it as a ZIP.
     *
     * @return array{0: ZipArchive, 1: string}
     */
    private function openZip(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'taskr-backup-test-');
        file_put_contents($path, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path), 'The download must be a valid ZIP archive.');

        return [$zip, $path];
    }

    private function closeZip(ZipArchive $zip, string $path): void
    {
        $zip->close();
        @unlink($path);
    }

    /**
     * The content of one archive entry, asserted to exist.
     */
    private function zipEntry(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        $this->assertNotFalse($content, "The archive must contain a {$name} entry.");

        return $content;
    }

    /**
     * Every entry of the archive concatenated — for absence checks.
     */
    private function zipContents(ZipArchive $zip): string
    {
        $all = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= (string) $zip->getFromIndex($i);
        }

        return $all;
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

    public function test_backup_requires_authentication(): void
    {
        $this->get(route('backup.download'))->assertRedirect(route('login'));
    }

    public function test_backup_is_refused_for_a_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($member)->get(route('backup.download'))->assertForbidden();
    }

    public function test_backup_is_refused_for_a_guest(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->memberOf($workspace, 'guest');

        $this->actingAs($guest)->get(route('backup.download'))->assertForbidden();
    }

    public function test_backup_contains_every_domain(): void
    {
        // Knowledge pages vectorize asynchronously: the job never runs here.
        Queue::fake();

        [$owner, $workspace] = $this->setupWorkspace();

        $activeProject = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $archivedProject = CreateProject::run($workspace, $owner, ['name' => 'Ancien projet']);
        ArchiveProject::run($archivedProject, $owner);

        $issue = CreateIssue::run($workspace, ['project_id' => $activeProject->id, 'title' => 'Page de profil']);
        $archivedIssue = CreateIssue::run($workspace, ['project_id' => $activeProject->id, 'title' => 'Ancien ticket']);
        $archivedIssue->update(['archived_at' => now()]);

        CreateTimeEntry::run($issue, $owner, null, 1.5);
        AddComment::run($issue, $owner, 'Un commentaire de sauvegarde');
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Guide', 'body' => 'Corps de la page']);

        $response = $this->actingAs($owner)->get(route('backup.download'));

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'sauvegarde-ws-'.now()->format('Y-m-d').'.zip',
            (string) $response->headers->get('Content-Disposition'),
            'The archive name carries the workspace and the date.',
        );

        [$zip, $path] = $this->openZip($response->streamedContent());

        // The reading guide names the workspace and the generation date.
        $readme = $this->zipEntry($zip, 'lisez-moi.md');
        $this->assertStringContainsString('WS', $readme);
        $this->assertStringContainsString(now()->format('Y-m-d'), $readme);

        // Projects: both, the archived one included, with its archived date.
        $projectRows = collect($this->parseCsv($this->zipEntry($zip, 'projets.csv')))->slice(1);
        $this->assertEqualsCanonicalizing(
            ['Ancien projet', 'Site vitrine'],
            $projectRows->pluck(1)->all(),
        );
        $archivedRow = $projectRows->first(fn (array $row): bool => $row[1] === 'Ancien projet');
        $this->assertNotNull($archivedRow);
        $this->assertNotSame('', $archivedRow[5], 'The archived project carries its archived date.');

        // Tickets: both, the archived one included.
        $ticketIds = collect($this->parseCsv($this->zipEntry($zip, 'tickets.csv')))->slice(1)->pluck(0);
        $this->assertEqualsCanonicalizing([$issue->id, $archivedIssue->id], $ticketIds->all());

        // Time entries: the whole history, no period bound.
        $this->assertStringContainsString('1.50', $this->zipEntry($zip, 'temps.csv'));

        // Comments: author and body.
        $commentsCsv = $this->zipEntry($zip, 'commentaires.csv');
        $this->assertStringContainsString('Un commentaire de sauvegarde', $commentsCsv);
        $this->assertStringContainsString($owner->name, $commentsCsv);

        // Knowledge: the page body, as Markdown.
        $this->assertStringContainsString('Corps de la page', $this->zipEntry($zip, 'knowledge/guide.md'));

        $this->closeZip($zip, $path);
    }

    public function test_backup_knowledge_tree_mirrors_the_parent_children_hierarchy(): void
    {
        // Knowledge pages vectorize asynchronously: the job never runs here.
        Queue::fake();

        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps racine']);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'Corps enfant',
            'parent_id' => $root->id,
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Petit enfant',
            'body' => 'Corps petit enfant',
            'parent_id' => $child->id,
        ]);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page transverse', 'body' => 'Corps transverse']);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page projet',
            'body' => 'Corps projet',
            'project_id' => $project->id,
        ]);

        [$zip, $path] = $this->openZip($this->actingAs($owner)->get(route('backup.download'))->streamedContent());

        // Directory nesting mirrors the parent/children structure.
        $this->assertNotFalse($zip->locateName('knowledge/racine.md'));
        $this->assertNotFalse($zip->locateName('knowledge/racine/enfant.md'));
        $this->assertNotFalse($zip->locateName('knowledge/racine/enfant/petit-enfant.md'));
        $this->assertNotFalse($zip->locateName('knowledge/page-transverse.md'));
        $this->assertNotFalse($zip->locateName('knowledge/page-projet.md'));

        // Each file carries the current body and the metadata header.
        $rootFile = $this->zipEntry($zip, 'knowledge/racine.md');
        $this->assertStringContainsString('Corps racine', $rootFile);
        $this->assertStringContainsString('Projet : Transverse', $rootFile);
        $this->assertStringContainsString('Parent : —', $rootFile);

        $childFile = $this->zipEntry($zip, 'knowledge/racine/enfant.md');
        $this->assertStringContainsString('Corps enfant', $childFile);
        $this->assertStringContainsString('Parent : Racine', $childFile);

        $projectFile = $this->zipEntry($zip, 'knowledge/page-projet.md');
        $this->assertStringContainsString('Corps projet', $projectFile);
        $this->assertStringContainsString('Projet : Site vitrine', $projectFile);

        $this->closeZip($zip, $path);
    }

    public function test_backup_excludes_trashed_knowledge_pages_and_past_versions(): void
    {
        // Knowledge pages vectorize asynchronously: the job never runs here.
        Queue::fake();

        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Vivante', 'body' => 'Corps initial']);
        UpdateKnowledgePage::run($page, $owner, ['title' => 'Vivante', 'body' => 'Corps actuel']);

        KnowledgePage::factory()->trashed($owner)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Supprimee',
            'body' => 'Corps supprime',
        ]);

        [$zip, $path] = $this->openZip($this->actingAs($owner)->get(route('backup.download'))->streamedContent());

        // The live page carries its current body, never the past version's.
        $liveFile = $this->zipEntry($zip, 'knowledge/vivante.md');
        $this->assertStringContainsString('Corps actuel', $liveFile);
        $this->assertStringNotContainsString('Corps initial', $liveFile);

        // The trashed page is absent from the backup.
        $this->assertFalse($zip->locateName('knowledge/supprimee.md'));
        $this->assertStringNotContainsString('Corps supprime', $this->zipContents($zip));

        $this->closeZip($zip, $path);
    }

    public function test_backup_has_no_row_limit(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        // Far below the shipped export ceiling: the backup is the escape
        // hatch, it must never refuse on volume.
        config(['taskr.export_max_rows' => 1]);

        $issues = [];
        foreach (['A', 'B', 'C'] as $title) {
            $issues[] = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title]);
        }

        $response = $this->actingAs($owner)->get(route('backup.download'));

        $response->assertOk();

        [$zip, $path] = $this->openZip($response->streamedContent());

        $ticketIds = collect($this->parseCsv($this->zipEntry($zip, 'tickets.csv')))->slice(1)->pluck(0);
        $this->assertEqualsCanonicalizing(array_map(fn (Issue $issue): string => $issue->id, $issues), $ticketIds->all());

        $this->closeZip($zip, $path);
    }

    public function test_backup_stays_scoped_to_the_current_workspace(): void
    {
        // Knowledge pages vectorize asynchronously: the job never runs here.
        Queue::fake();

        [$owner, $workspace] = $this->setupWorkspace('Alpha');
        $project = CreateProject::run($workspace, $owner, ['name' => 'Projet A']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Ticket Alpha']);
        AddComment::run($issue, $owner, 'Commentaire Alpha');
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page Alpha', 'body' => 'Corps Alpha']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Beta']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Projet B']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Ticket Beta']);
        AddComment::run($otherIssue, $otherOwner, 'Commentaire Beta');
        CreateKnowledgePage::run($otherWorkspace, $otherOwner, ['title' => 'Page Beta', 'body' => 'Corps Beta']);

        app(WorkspaceContext::class)->set($workspace);

        [$zip, $path] = $this->openZip($this->actingAs($owner)->get(route('backup.download'))->streamedContent());

        $all = $this->zipContents($zip);

        // Everything of the current workspace is in.
        $this->assertStringContainsString('Ticket Alpha', $all);
        $this->assertStringContainsString('Commentaire Alpha', $all);
        $this->assertStringContainsString('Corps Alpha', $all);

        // Nothing of the other workspace leaks in.
        $this->assertStringNotContainsString('Ticket Beta', $all);
        $this->assertStringNotContainsString('Commentaire Beta', $all);
        $this->assertStringNotContainsString('Corps Beta', $all);
        $this->assertStringNotContainsString('Projet B', $all);

        $this->closeZip($zip, $path);
    }
}
