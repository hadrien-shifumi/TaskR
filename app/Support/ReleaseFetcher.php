<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Checks GitHub for the latest published TaskR release — used by
 * `taskr:update` to compare against the installed VERSION file. Kept as a
 * thin, stateless service so tests can swap it out for a fake (see
 * DatabaseProber).
 */
class ReleaseFetcher
{
    private const REPOSITORY = 'hadrien-shifumi/TaskR';

    /**
     * @return array{version: string, tag: string, tarballUrl: string, url: string}|null
     */
    public function latest(): ?array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TaskR-Updater',
        ])->get('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest');

        if ($response->failed()) {
            return null;
        }

        $tag = (string) $response->json('tag_name', '');
        $tarballUrl = (string) $response->json('tarball_url', '');

        if ($tag === '' || $tarballUrl === '') {
            return null;
        }

        return [
            'version' => ltrim($tag, 'v'),
            'tag' => $tag,
            'tarballUrl' => $tarballUrl,
            'url' => (string) $response->json('html_url', ''),
        ];
    }
}
