<?php

use App\Models\Membership;
use App\Models\User;
use PHPUnit\Framework\Assert;

const EXPORT_ISSUE_ROWS = '[data-test^="issue-row-"]';

/**
 * Polyfill the in-page download machinery. The export flows hand the file
 * to the browser through `URL.createObjectURL` + a programmatic click on
 * an anchor carrying `download` — neither is capturable by the plugin, so
 * both are wrapped: the blob is kept on `window.__exportBlob` and the
 * file name on `window.__exportDownload`. The real download is swallowed
 * (the anchor click never leaves the page), everything before it — the
 * request, the response, the name, the content — stays observable.
 */
function captureBrowserDownload($page): void
{
    $page->script(<<<'JS'
        window.__exportBlob = null;
        window.__exportDownload = null;

        const createObjectURL = URL.createObjectURL.bind(URL);

        URL.createObjectURL = (blob) => {
            window.__exportBlob = blob;

            return createObjectURL(blob);
        };

        const anchorClick = HTMLAnchorElement.prototype.click;

        HTMLAnchorElement.prototype.click = function () {
            if (this.hasAttribute('download')) {
                window.__exportDownload = this.download;

                return;
            }

            return anchorClick.call(this);
        };

        // A trailing non-function statement: the evaluator calls the value
        // of the last expression when it is a function — which would invoke
        // the override above with no receiver and throw.
        window.__exportCaptureInstalled = true;
        JS);
}

/** Forget the previous capture so the next export is proven on its own. */
function resetCapturedDownload($page): void
{
    $page->script('window.__exportBlob = null; window.__exportDownload = null');
}

/**
 * Wait (bounded) for the captured export blob and return its leading bytes
 * and its decoded text. The UTF-8 BOM is proven at the byte level — text
 * decoding consumes it, so it is invisible in the decoded text.
 *
 * @return array{magic: string, text: string}
 */
function capturedDownload($page): array
{
    $capture = $page->script(<<<'JS'
        async () => {
            const deadline = Date.now() + 10000;

            while (window.__exportBlob === null && Date.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            if (window.__exportBlob === null) {
                return null;
            }

            const buffer = await window.__exportBlob.arrayBuffer();
            const bytes = new Uint8Array(buffer);

            let magic = '';

            for (let i = 0; i < 3 && i < bytes.length; i++) {
                magic += String.fromCharCode(bytes[i]);
            }

            return { magic, text: new TextDecoder().decode(bytes) };
        }
        JS);

    if (! is_array($capture)) {
        Assert::fail('The export did not hand a file to the browser within 10 seconds.');
    }

    return ['magic' => (string) $capture['magic'], 'text' => (string) $capture['text']];
}

/** The file name the export flow gave to the download, if any. */
function capturedDownloadName($page): ?string
{
    $name = $page->script('window.__exportDownload');

    return is_string($name) ? $name : null;
}

/** The titles of the issue rows currently on screen, in display order. */
function visibleIssueTitles($page): array
{
    return $page->script(<<<'JS'
        () => Array.from(document.querySelectorAll('[data-test^="issue-row-"]'))
            .map((row) => row.querySelector('[data-test^="issue-title-"]').textContent.trim())
        JS);
}

it('exports the filtered issues as CSV and JSON with a counter that follows the filter', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/issues');

    // The export counter starts at the unfiltered total (10 seeded issues).
    $page->assertSeeIn('@issues-export', 'Export (10)');
    $page->assertCount(EXPORT_ISSUE_ROWS, 10);

    // Narrow the list through the UI: the counter follows the filter.
    $page->click('@filter-select-category')
        ->click('[role="option"]:has-text("Done")');
    $page->assertQueryStringHas('category', 'done');
    $page->assertCount(EXPORT_ISSUE_ROWS, 4);
    $page->assertSeeIn('@issues-export', 'Export (4)');

    // CSV export: dated file name, UTF-8 BOM, French headers, and exactly
    // the tickets on screen — the filters travelled to the file.
    captureBrowserDownload($page);
    $page->click('@issues-export')
        ->click('@issues-export-csv');

    $csv = capturedDownload($page);

    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $csv['text'])),
        fn (string $line) => $line !== '',
    ));

    expect($csv['magic'])->toBe("\u{00EF}\u{00BB}\u{00BF}")
        ->and(array_slice(str_getcsv($lines[0]), 0, 4))->toBe(['Identifiant', 'Titre', 'Projet', 'Statut'])
        ->and($lines)->toHaveCount(5); // header + the 4 visible rows

    $csvTitles = array_map(
        fn (string $line) => str_getcsv($line)[1],
        array_slice($lines, 1),
    );

    expect($csvTitles)->toEqualCanonicalizing(visibleIssueTitles($page));
    expect(capturedDownloadName($page))->toBe('tickets-'.now()->format('Y-m-d').'.csv');

    // JSON export of the same filtered view: a bare array that decodes.
    resetCapturedDownload($page);
    $page->click('@issues-export')
        ->click('@issues-export-json');

    $rows = json_decode(capturedDownload($page)['text'], true);

    expect($rows)->toBeArray()->toHaveCount(4)
        ->and(array_column($rows, 'title'))->toEqualCanonicalizing(visibleIssueTitles($page))
        ->and($rows[0]['project'])->toBe('Refonte site vitrine');
    expect(capturedDownloadName($page))->toBe('tickets-'.now()->format('Y-m-d').'.json');

    $page->assertNoSmoke();
});

it('refuses the issues export beyond the volume limit with the server message', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    seedProjectWithHealth($workspace, $owner);
    config(['taskr.export_max_rows' => 2]);
    $this->actingAs($owner);

    $page = visit('/issues');

    // 10 seeded issues against a limit lowered to 2 for the scenario:
    // the export is refused, the localized message shows and no file is
    // handed to the browser.
    captureBrowserDownload($page);
    $page->click('@issues-export')
        ->click('@issues-export-csv');

    $page->assertSeeIn('@export-error', 'The export is limited to 2 tickets. Narrow the filters to reduce the volume.');
    expect(capturedDownloadName($page))->toBeNull();

    $page->assertNoSmoke();
});

it('exports the time entries of the chosen period from the project health page', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    // The export dialog opens with format and a bounded period.
    $page->click('@time-export');
    $page->assertVisible('@time-export-format');

    $today = now()->toDateString();

    // A period covering the three seeded entries (all worked today).
    $page->fill('@time-export-after', now()->startOfMonth()->toDateString())
        ->fill('@time-export-before', $today);

    captureBrowserDownload($page);
    $page->click('@time-export-submit');

    $csv = capturedDownload($page);

    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $csv['text'])),
        fn (string $line) => $line !== '',
    ));

    // Dated file name, French headers, the three entries of the period.
    expect($csv['magic'])->toBe("\u{00EF}\u{00BB}\u{00BF}")
        ->and(array_slice(str_getcsv($lines[0]), 0, 5))->toBe(['Identifiant', 'Ticket', 'Titre du ticket', 'Projet', 'Auteur'])
        ->and($lines)->toHaveCount(4); // header + the 3 entries

    $entries = array_slice($lines, 1);

    foreach ($entries as $entry) {
        expect(str_getcsv($entry)[5])->toBe($today); // Date du travail
    }

    expect(array_map(fn (string $entry) => str_getcsv($entry)[2], $entries))
        ->toEqualCanonicalizing(['B1', 'IP2', 'D1']);
    expect($csv['text'])->toContain(',100.00,')->toContain(',4.00,');
    expect(capturedDownloadName($page))->toBe('saisies-temps-'.now()->format('Y-m-d').'.csv');

    // A period that covers no entry exports the header only: the chosen
    // window truly bounds the export.
    resetCapturedDownload($page);
    $page->click('@time-export')
        ->fill('@time-export-after', now()->addDay()->toDateString())
        ->fill('@time-export-before', now()->addDays(2)->toDateString())
        ->click('@time-export-submit');

    $empty = capturedDownload($page);

    $emptyLines = array_values(array_filter(
        array_map('trim', explode("\n", $empty['text'])),
        fn (string $line) => $line !== '',
    ));

    expect($emptyLines)->toHaveCount(1); // header only
    expect(capturedDownloadName($page))->toBe('saisies-temps-'.now()->format('Y-m-d').'.csv');

    $page->assertNoSmoke();
});

it('refuses the time export beyond the volume limit with the server message', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    config(['taskr.export_max_rows' => 2]);
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    // Three seeded entries against a limit lowered to 2 for the scenario.
    $page->click('@time-export');

    captureBrowserDownload($page);
    $page->click('@time-export-submit');

    $page->assertSeeIn('@export-error', 'The export is limited to 2 time entries. Narrow the filters to reduce the volume.');
    expect(capturedDownloadName($page))->toBeNull();

    // The dialog stays open, inviting the user to narrow the period.
    $page->assertVisible('@time-export-format');

    $page->assertNoSmoke();
});

it('hands the owner the complete workspace backup as a zip archive', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/');

    // The backup route answers an attachment: navigating to it would be a
    // download the plugin cannot observe, so it is fetched in-page and
    // inspected from the response itself.
    $backup = $page->script(<<<'JS'
        async () => {
            const response = await fetch('/backup');
            const buffer = await response.arrayBuffer();
            const bytes = new Uint8Array(buffer);

            return {
                status: response.status,
                type: response.headers.get('content-type'),
                disposition: response.headers.get('content-disposition'),
                size: buffer.byteLength,
                magic: String.fromCharCode(bytes[0], bytes[1]),
            };
        }
        JS);

    expect($backup['status'])->toBe(200)
        ->and($backup['type'])->toBe('application/zip')
        ->and($backup['magic'])->toBe('PK')
        ->and($backup['size'])->toBeGreaterThan(1000)
        ->and($backup['disposition'])->toContain('sauvegarde-ws-'.now()->format('Y-m-d').'.zip');

    $page->assertNoSmoke();
});

it('refuses the workspace backup to a non-owner', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $member = User::factory()->create();
    Membership::create(['user_id' => $member->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
    $this->actingAs($member);

    // The member browses the workspace normally — the refusal targets the
    // backup, not the session.
    $page = visit('/');
    $page->assertSee('WS');

    $status = $page->script(<<<'JS'
        async () => {
            const response = await fetch('/backup');
            await response.arrayBuffer();

            return response.status;
        }
        JS);

    expect($status)->toBe(403);

    $page->assertNoSmoke();
});
