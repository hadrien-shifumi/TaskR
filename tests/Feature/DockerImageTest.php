<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The published Docker image's contract, frozen repo-level: what the
 * container refuses to start with, and what each role needs to come up
 * healthy. The Mercure preflight and the storage skeleton are executed for
 * real, under a POSIX shell; the rest pins the wiring between docker/,
 * Dockerfile and docker-compose.yml.
 */
class DockerImageTest extends TestCase
{
    /**
     * Run the image's preflight with exactly the given environment.
     *
     * @param  array<string, string>  $environment
     */
    private function preflight(array $environment): Process
    {
        // Symfony merges the parent's environment into what it is given: the
        // variables under test are always set explicitly, so the developer's
        // own .env (loaded by Laravel with putenv()) can't leak in.
        $environment = $environment + ['MERCURE_JWT_SECRET' => '', 'PATH' => (string) getenv('PATH')];
        $process = new Process(['/bin/sh', base_path('docker/preflight.sh')], base_path(), $environment, null, 30);
        $process->run();

        return $process;
    }

    public function test_the_preflight_accepts_a_configuration_without_mercure(): void
    {
        foreach ([[], ['BROADCAST_CONNECTION' => 'log'], ['BROADCAST_CONNECTION' => 'null']] as $environment) {
            $process = $this->preflight($environment);
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), json_encode($environment).': '.$output);
            $this->assertSame('', $output);
        }
    }

    public function test_the_preflight_refuses_mercure_without_a_secret(): void
    {
        // .env.example ships exactly this pair, ready to be copied: the hub
        // asked for, the secret left to fill in.
        foreach ([['BROADCAST_CONNECTION' => 'mercure'], ['BROADCAST_CONNECTION' => 'mercure', 'MERCURE_JWT_SECRET' => '']] as $environment) {
            $process = $this->preflight($environment);

            $this->assertSame(1, $process->getExitCode(), json_encode($environment));
            $this->assertStringContainsString('MERCURE_JWT_SECRET', $process->getErrorOutput());
            $this->assertStringContainsString('openssl rand -hex 32', $process->getErrorOutput());
        }
    }

    public function test_the_preflight_refuses_a_secret_too_short_to_sign_hs256_tokens(): void
    {
        // 32 bytes is the key size HS256 signs with: the framework's own floor.
        $tooShort = $this->preflight(['BROADCAST_CONNECTION' => 'mercure', 'MERCURE_JWT_SECRET' => str_repeat('a', 31)]);
        $this->assertSame(1, $tooShort->getExitCode());
        $this->assertStringContainsString('MERCURE_JWT_SECRET', $tooShort->getErrorOutput());
        $this->assertStringContainsString('openssl rand -hex 32', $tooShort->getErrorOutput());

        $shortestValid = $this->preflight(['BROADCAST_CONNECTION' => 'mercure', 'MERCURE_JWT_SECRET' => str_repeat('a', 32)]);
        $this->assertSame(0, $shortestValid->getExitCode(), $shortestValid->getErrorOutput());
    }

    public function test_the_preflight_refuses_a_secret_containing_whitespace(): void
    {
        // Split across the web and the worker roles, a secret holding
        // whitespace parses differently from one dotenv reader to the next.
        foreach ([' ', "\n", "\t"] as $whitespace) {
            $process = $this->preflight(['BROADCAST_CONNECTION' => 'mercure', 'MERCURE_JWT_SECRET' => str_repeat('a', 32).$whitespace.str_repeat('a', 31)]);

            $this->assertSame(1, $process->getExitCode(), json_encode($whitespace));
            $this->assertStringContainsString('MERCURE_JWT_SECRET', $process->getErrorOutput());
            $this->assertStringContainsString('openssl rand -hex 32', $process->getErrorOutput());
        }
    }

    public function test_the_preflight_accepts_the_documented_secret_format(): void
    {
        // `openssl rand -hex 32`: bare hex, 64 characters.
        $process = $this->preflight(['BROADCAST_CONNECTION' => 'mercure', 'MERCURE_JWT_SECRET' => bin2hex(random_bytes(32))]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_the_entrypoint_runs_the_preflight_before_touching_storage_or_the_database(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        // First thing after landing in the app: nothing chowns, waits or
        // migrates before an unusable configuration is reported.
        $preflight = strpos($entrypoint, '/usr/local/bin/preflight.sh');
        $this->assertNotFalse($preflight);

        foreach (['chown', 'Waiting for database', 'migrate'] as $later) {
            $this->assertNotFalse(strpos($entrypoint, $later));
            $this->assertLessThan(strpos($entrypoint, $later), $preflight, "The preflight must run before: {$later}");
        }

        // And the image ships the script the entrypoint calls.
        $this->assertStringContainsString('docker/preflight.sh', file_get_contents(base_path('Dockerfile')));
    }

    public function test_the_entrypoint_creates_the_storage_skeleton_on_a_bare_volume(): void
    {
        // The entrypoint runs under POSIX sh (dash in the image), which has no
        // brace expansion: a storage volume that starts out empty must still
        // get Laravel's skeleton, or the boot aborts at view:cache with
        // "View path not found".
        $lines = file(base_path('docker/entrypoint.sh'), FILE_IGNORE_NEW_LINES);
        $start = array_search(true, array_map(fn (string $line) => str_starts_with($line, 'mkdir -p '), $lines), true);
        $this->assertNotFalse($start, 'The entrypoint no longer creates the storage skeleton.');

        $command = '';
        for ($index = $start; isset($lines[$index]); $index++) {
            $command .= rtrim($lines[$index], '\\').' ';
            if (! str_ends_with(rtrim($lines[$index]), '\\')) {
                break;
            }
        }

        $directory = sys_get_temp_dir().'/taskr-skeleton-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            // macOS ships bash as /bin/sh, which does expand braces: dash
            // when it is installed, to run what the image runs.
            $shell = (new ExecutableFinder)->find('dash', '/bin/sh');
            (new Process([$shell, '-c', 'cd "$1" && eval "$2"', 'sh', $directory, $command], $directory, null, null, 30))->mustRun();

            foreach ([
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/framework/testing',
                'storage/framework/views',
                'storage/logs',
                'storage/app/private',
                'bootstrap/cache',
            ] as $path) {
                $this->assertDirectoryExists($directory.'/'.$path);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_the_example_env_starts_the_bundled_database(): void
    {
        // The quick start copies .env.example as is: without the profile, the
        // bundled postgres is never started and the app waits for a database
        // that never comes up.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^COMPOSE_PROFILES=bundled-db\b/m', $example);
    }

    public function test_the_sources_carry_the_license_they_declare(): void
    {
        // composer.json declares MIT: the license text ships with the sources
        // and the release workflow states it on the image.
        $this->assertSame('MIT', json_decode((string) file_get_contents(base_path('composer.json')), true)['license'] ?? null);

        $this->assertFileExists(base_path('LICENSE'));
        $license = (string) file_get_contents(base_path('LICENSE'));
        $this->assertStringContainsString('MIT License', $license);
        $this->assertStringContainsString('Copyright (c) 2026 Shifumi', $license);

        $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
        $this->assertStringContainsString('org.opencontainers.image.licenses=MIT', $release);
        // Left alone, the vendor label is inherited from the base image —
        // FrankenPHP's author, not TaskR's.
        $this->assertStringContainsString('org.opencontainers.image.vendor=Shifumi', $release);
    }

    public function test_the_worker_healthcheck_does_not_depend_on_a_tool_missing_from_the_image(): void
    {
        // The FrankenPHP base image ships no procps: `pgrep -f` answered
        // "not found", which left the worker unhealthy forever. The command
        // is what matters — comments may still name the tool.
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertDoesNotMatchRegularExpression('/test:\s*\[[^\]]*pgrep/', $compose);
        $this->assertMatchesRegularExpression('/worker:.*?healthcheck:/s', $compose);
    }
}
