<?php

namespace Tests\Unit;

use App\Support\ReleaseFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ReleaseFetcher — asks the GitHub API for the latest published TaskR
 * release, used by `taskr:update` to compare against the installed VERSION.
 */
class ReleaseFetcherTest extends TestCase
{
    public function test_latest_returns_the_version_tag_and_tarball_url(): void
    {
        Http::fake([
            'api.github.com/repos/hadrien-shifumi/TaskR/releases/latest' => Http::response([
                'tag_name' => 'v0.2.0',
                'tarball_url' => 'https://api.github.com/repos/hadrien-shifumi/TaskR/tarball/v0.2.0',
                'html_url' => 'https://github.com/hadrien-shifumi/TaskR/releases/tag/v0.2.0',
            ]),
        ]);

        $latest = app(ReleaseFetcher::class)->latest();

        $this->assertSame([
            'version' => '0.2.0',
            'tag' => 'v0.2.0',
            'tarballUrl' => 'https://api.github.com/repos/hadrien-shifumi/TaskR/tarball/v0.2.0',
            'url' => 'https://github.com/hadrien-shifumi/TaskR/releases/tag/v0.2.0',
        ], $latest);
    }

    public function test_latest_returns_null_when_the_api_call_fails(): void
    {
        Http::fake([
            'api.github.com/repos/hadrien-shifumi/TaskR/releases/latest' => Http::response(null, 404),
        ]);

        $this->assertNull(app(ReleaseFetcher::class)->latest());
    }

    public function test_latest_returns_null_when_the_response_is_missing_expected_fields(): void
    {
        Http::fake([
            'api.github.com/repos/hadrien-shifumi/TaskR/releases/latest' => Http::response(['name' => 'Oops, no tag_name']),
        ]);

        $this->assertNull(app(ReleaseFetcher::class)->latest());
    }

    public function test_latest_sends_a_user_agent_header_as_required_by_the_github_api(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v0.2.0',
                'tarball_url' => 'https://api.github.com/repos/hadrien-shifumi/TaskR/tarball/v0.2.0',
                'html_url' => 'https://github.com/hadrien-shifumi/TaskR/releases/tag/v0.2.0',
            ]),
        ]);

        app(ReleaseFetcher::class)->latest();

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent'));
    }
}
