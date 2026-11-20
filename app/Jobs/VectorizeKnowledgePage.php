<?php

namespace App\Jobs;

use App\Models\KnowledgePage;
use App\Support\Embeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Embeds a knowledge page (title + body) and stores the vector. Idempotent:
 * the same content always produces the same stored state. Retried with
 * backoff when the embeddings endpoint is down — the page stays readable
 * and simply stays out of semantic search until this succeeds.
 *
 * The provider is resolved from the page's own workspace at run time —
 * not from the config at dispatch time — so a provider change applies to
 * queued jobs, and each workspace follows its own configuration.
 */
class VectorizeKnowledgePage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public KnowledgePage $page) {}

    public function handle(): void
    {
        $page = $this->page->withoutWorkspaceScope()->findOrFail($this->page->id);

        $vector = Embeddings::forWorkspaceId($page->workspace_id)->embed($page->title."\n\n".$page->body);

        $page->forceFill([
            'embedding' => $vector,
            'embedded_at' => now(),
        ])->save();
    }
}
