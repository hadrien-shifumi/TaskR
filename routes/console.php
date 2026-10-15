<?php

use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('knowledge:reembed', function (): void {
    KnowledgePage::withoutWorkspaceScope()
        ->orderBy('id')
        ->each(fn (KnowledgePage $page) => VectorizeKnowledgePage::dispatch($page));
})->purpose('Re-run embedding vectorization for every knowledge page (use after changing the embeddings model or dimensions)');
