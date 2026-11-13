<?php

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\Http;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Downloads a GitHub release tarball, extracts it and copies its tracked
 * files over an existing installation directory — the file-level
 * equivalent of `git pull` for `composer create-project` installs, which
 * have no .git history to fast-forward. Only ever overwrites or adds files
 * present in the release archive: since GitHub's archive only ever
 * contains git-tracked paths, `.env`, `storage/` data, `vendor/` and
 * `node_modules/` are never touched.
 */
class SourceUpdater
{
    /**
     * Downloads, extracts and copies the release into $targetDir. Returns
     * the number of files written.
     */
    public function applyRelease(string $tarballUrl, string $targetDir): int
    {
        $workDir = sys_get_temp_dir().'/taskr-update-'.uniqid();
        mkdir($workDir, 0777, true);

        try {
            $archive = $this->download($tarballUrl, $workDir);
            $sourceDir = $this->extract($archive, $workDir);

            return $this->copyInto($sourceDir, $targetDir);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function download(string $url, string $workDir): string
    {
        $response = Http::withHeaders(['User-Agent' => 'TaskR-Updater'])->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Could not download the release archive ({$response->status()}).");
        }

        $archive = $workDir.'/release.tar.gz';
        file_put_contents($archive, $response->body());

        return $archive;
    }

    private function extract(string $archive, string $workDir): string
    {
        $extractDir = $workDir.'/extracted';
        mkdir($extractDir, 0777, true);

        try {
            (new PharData($archive))->extractTo($extractDir);
        } catch (\Exception $exception) {
            throw new RuntimeException("Could not extract the release archive: {$exception->getMessage()}");
        }

        $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));

        if (count($entries) !== 1 || ! is_dir($extractDir.'/'.$entries[0])) {
            throw new RuntimeException('Unexpected release archive layout — expected a single top-level directory.');
        }

        return $extractDir.'/'.$entries[0];
    }

    private function copyInto(string $sourceDir, string $targetDir): int
    {
        $written = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
            $destination = $targetDir.'/'.$relative;

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }

                continue;
            }

            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }

            copy($item->getPathname(), $destination);
            $written++;
        }

        return $written;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
