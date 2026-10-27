<?php

namespace App\Console\Commands;

use App\Models\KnowledgePage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('knowledge:purge-trash
            {--days=30 : Number of days to keep trashed pages}
            {--dry-run : Show what would be deleted without deleting}')]
#[Description('Permanently delete knowledge pages that have been in the trash for more than the retention period (default: 30 days).')]
class PurgeKnowledgeTrash extends Command
{
    /**
     * Rétention par défaut : 30 jours.
     */
    public const RETENTION_DAYS = 30;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        // Query: pages soft-deleted before the cutoff date.
        // We bypass the workspace scope to process all workspaces.
        $query = KnowledgePage::withoutWorkspaceScope()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No trashed pages to purge.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[DRY RUN] Would purge {$count} page(s) trashed before {$cutoff->toDateTimeString()}:");
            $query->cursor()->each(function (KnowledgePage $page): void {
                $this->line("  - [{$page->id}] {$page->title} (trashed at {$page->deleted_at?->toDateTimeString()}, reason: {$page->deleted_reason})");
            });

            return self::SUCCESS;
        }

        // Force delete each page.
        // Children are orphaned via nullOnDelete FK; versions cascade via FK.
        $deleted = 0;
        $query->cursor()->each(function (KnowledgePage $page) use (&$deleted): void {
            $page->forceDelete();
            $deleted++;
        });

        $this->info("Purged {$deleted} page(s) trashed before {$cutoff->toDateTimeString()} (retention: {$days} days).");

        return self::SUCCESS;
    }
}
