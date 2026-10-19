<?php

use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Support\WebpConverter;
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

Artisan::command('images:webp {input : Path to the source JPG or PNG} {--sizes=1536,1024,768,480 : Comma-separated target widths} {--quality=82 : WebP quality, 0-100} {--output-dir= : Directory for the generated files, default is next to the input} {--icon : Generate the site icon set instead of the size variants}', function (): int {
    $input = (string) $this->argument('input');
    $outputDir = $this->option('output-dir');
    $converter = new WebpConverter((int) $this->option('quality'));

    try {
        if ($this->option('icon')) {
            $target = $outputDir ?? public_path();
            $files = $converter->generateIcons($input, $target);
            $this->info('Site icons generated in '.$target.':');
        } else {
            $sizes = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('sizes')))));
            $target = $outputDir ?? pathinfo(realpath($input) ?: $input, PATHINFO_DIRNAME);
            $files = $converter->convert($input, $target, $sizes);
            $this->info('WebP files generated in '.$target.':');
        }
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    foreach ($files as $file) {
        $this->line('  '.$file);
    }

    return 0;
})->purpose('Convert a JPG/PNG to optimized webp size variants, or generate the site icon set with --icon');
