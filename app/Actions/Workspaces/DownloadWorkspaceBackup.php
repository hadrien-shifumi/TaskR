<?php

namespace App\Actions\Workspaces;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Comments\CommentExportPresenter;
use App\Presenters\Issues\IssueExportPresenter;
use App\Presenters\Projects\ProjectExportPresenter;
use App\Presenters\TimeTracking\TimeEntryExportPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsController;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class DownloadWorkspaceBackup
{
    use AsController;

    /**
     * Download the complete backup of the workspace as a single ZIP
     * archive: every project (archived included), every ticket
     * (archived included), every time entry (no period bound), every
     * comment, and every live knowledge page as a Markdown file
     * nested in directories that mirror the parent/children tree.
     *
     * This is the escape hatch of the tool: unlike the filtered
     * exports, it carries no volume ceiling — it must never refuse
     * to hand the owner their data.
     */
    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    public function asController(): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        [$path, $filename] = $this->buildArchive($workspace);

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            unlink($path);
        }, $filename, ['Content-Type' => 'application/zip']);
    }

    /**
     * Build the archive on disk and name it. The ZIP format needs a
     * real file, so the archive is assembled in a temporary file and
     * streamed afterwards — the response never holds it in memory.
     *
     * @return array{0: string, 1: string}
     */
    private function buildArchive(Workspace $workspace): array
    {
        $projects = Project::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $issues = Issue::query()
            ->with(['project', 'status', 'labels', 'assignees', 'creator', 'parent'])
            ->withSum('timeEntries as logged_hours_sum', 'hours')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $entries = TimeEntry::query()
            ->with([
                'author',
                'issue' => fn ($issue) => $issue
                    ->with('project')
                    ->withSum('timeEntries as logged_hours_sum', 'hours'),
            ])
            ->orderByDesc('worked_at')
            ->orderBy('issue_id')
            ->orderBy('id')
            ->get();

        // Comments carry no workspace column: they are scoped through
        // the issues of the workspace, never queried workspace-wide.
        $comments = Comment::query()
            ->whereIn('issue_id', $issues->pluck('id')->all())
            ->with(['issue', 'author'])
            ->orderBy('issue_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Soft-deleted pages stay out: a backup reflects the living
        // content, the trash is transient by design (30 days).
        $pages = KnowledgePage::query()
            ->with(['project', 'parent'])
            ->orderBy('title')
            ->get();

        $path = tempnam(sys_get_temp_dir(), 'taskr-backup-');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('The workspace backup archive could not be created.');
        }

        $zip->addFromString('lisez-moi.md', $this->readme($workspace, $projects->count(), $issues->count(), $entries->count(), $comments->count(), $pages->count()));
        $zip->addFromString('projets.csv', $this->csv(
            ProjectExportPresenter::headers(),
            $projects->map(fn (Project $project): array => ProjectExportPresenter::csvValues(ProjectExportPresenter::row($project)))->all(),
        ));
        $zip->addFromString('tickets.csv', $this->csv(
            IssueExportPresenter::headers(),
            $issues->map(fn (Issue $issue): array => IssueExportPresenter::csvValues(IssueExportPresenter::row($issue)))->all(),
        ));
        $zip->addFromString('temps.csv', $this->csv(
            TimeEntryExportPresenter::headers(),
            $entries->map(fn (TimeEntry $entry): array => TimeEntryExportPresenter::csvValues(TimeEntryExportPresenter::row($entry)))->all(),
        ));
        $zip->addFromString('commentaires.csv', $this->csv(
            CommentExportPresenter::headers(),
            $comments->map(fn (Comment $comment): array => CommentExportPresenter::csvValues(CommentExportPresenter::row($comment)))->all(),
        ));

        foreach ($this->knowledgeEntries($pages) as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        $zip->close();

        return [$path, $this->filename($workspace)];
    }

    /**
     * The reading guide of the archive: what it contains, and how the
     * knowledge tree maps onto the directories.
     */
    private function readme(Workspace $workspace, int $projects, int $issues, int $entries, int $comments, int $pages): string
    {
        $lines = [
            '# Sauvegarde de l\'espace « '.$workspace->name.' »',
            '',
            'Générée le '.now()->format('Y-m-d H:i:s').'.',
            '',
            '## Contenu',
            '',
            "- {$projects} projet(s) — projets.csv (y compris archivés)",
            "- {$issues} ticket(s) — tickets.csv (y compris archivés)",
            "- {$entries} saisie(s) de temps — temps.csv",
            "- {$comments} commentaire(s) — commentaires.csv",
            "- {$pages} page(s) de connaissance — dossier knowledge/",
            '',
            '## Organisation de la connaissance',
            '',
            'Chaque page est un fichier Markdown ; une page enfant se trouve dans le',
            'dossier de sa page parente : l\'arborescence des dossiers reproduit celle',
            'des pages. Chaque fichier commence par un en-tête invisible (commentaire',
            'Markdown) rappelant le projet, la page parente et la dernière modification.',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * A CSV document as a string: explicit French headers, comma
     * separator, native escaping, UTF-8 BOM so a spreadsheet
     * renders the accents — byte-identical to the delivered exports.
     *
     * @param  list<string>  $headers
     * @param  array<int, list<string>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'wb');

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, $headers, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '\\');
        }

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * Flatten the pages into ZIP entries: one Markdown file per
     * page, nested in a directory per ancestor — the directory
     * tree mirrors the parent/children structure. A page whose
     * parent left the living set (trashed) falls back to the root.
     *
     * @param  Collection<int, KnowledgePage>  $pages
     * @return array<string, string>
     */
    private function knowledgeEntries(Collection $pages): array
    {
        $childrenOf = $pages->groupBy('parent_id');
        $livingIds = $pages->pluck('id')->flip();

        $entries = [];

        $walk = function (KnowledgePage $page, string $directory, array &$used) use (&$walk, $childrenOf, &$entries): void {
            $name = $this->entryName($page, $used);

            $entries[$directory.$name.'.md'] = $this->pageMarkdown($page);

            $children = $childrenOf->get($page->id, collect());
            $childUsed = [];

            foreach ($children as $child) {
                $walk($child, $directory.$name.'/', $childUsed);
            }
        };

        $rootUsed = [];

        foreach ($pages as $page) {
            if ($page->parent_id === null || ! $livingIds->has($page->parent_id)) {
                $walk($page, 'knowledge/', $rootUsed);
            }
        }

        return $entries;
    }

    /**
     * The directory/file base name of a page: its slugified title,
     * de-duplicated among its siblings, falling back to a fragment
     * of the identifier when the title carries nothing slugifiable.
     *
     * @param  list<string>  $used
     */
    private function entryName(KnowledgePage $page, array &$used): string
    {
        $base = Str::slug($page->title);

        if ($base === '') {
            $base = 'page-'.Str::lower(Str::substr($page->id, -6));
        }

        $name = $base;
        $suffix = 2;

        while (in_array($name, $used, true)) {
            $name = $base.'-'.$suffix;
            $suffix++;
        }

        $used[] = $name;

        return $name;
    }

    /**
     * The Markdown file of a page: an invisible metadata header
     * (project, parent, last modification) followed by the current
     * body, verbatim — portable into any Markdown reader.
     */
    private function pageMarkdown(KnowledgePage $page): string
    {
        $parent = $page->parent_id === null
            ? '—'
            : ($page->parent?->title ?? 'page supprimée');

        $header = sprintf(
            "<!--\nPage : %s\nProjet : %s\nParent : %s\nDernière modification : %s\n-->",
            $page->title,
            $page->project?->name ?? 'Transverse',
            $parent,
            $page->updated_at?->format('Y-m-d H:i:s') ?? '',
        );

        return $header."\n\n".$page->body;
    }

    /**
     * Dated archive name carrying the workspace: repeated backups
     * stay distinguishable in the downloads folder.
     */
    private function filename(Workspace $workspace): string
    {
        $slug = Str::slug($workspace->name);
        $slug = $slug !== '' ? $slug : 'workspace';

        return 'sauvegarde-'.$slug.'-'.now()->format('Y-m-d').'.zip';
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
