<?php

namespace App\Console\Commands;

use App\Support\ReleaseFetcher;
use App\Support\SourceUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[Signature('taskr:update {--check : Only report whether a newer release is available, without applying it} {--force : Apply the update without prompting (required when not running interactively)} {--path= : Installation directory to update, defaults to this application\'s base path}')]
#[Description('Checks GitHub for a newer TaskR release and, for composer create-project installs (no .git history), downloads and applies it in place.')]
class UpdateTaskr extends Command
{
    public function __construct(
        private readonly ReleaseFetcher $releases,
        private readonly SourceUpdater $updater,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: $this->laravel->basePath());
        $interactive = $this->input->isInteractive();

        if (is_dir($path.'/.git')) {
            $this->components->error('This looks like a git checkout — use `git pull` instead of `taskr:update`, which is for `composer create-project` installs that have no .git history to fast-forward.');

            return self::FAILURE;
        }

        $versionPath = $path.'/VERSION';

        if (! is_file($versionPath)) {
            $this->components->error('No VERSION file found — this installation predates `taskr:update`. Follow the manual update steps in the README, or re-install from a release that ships a VERSION file.');

            return self::FAILURE;
        }

        $current = trim((string) file_get_contents($versionPath));
        $latest = $this->releases->latest();

        if ($latest === null) {
            $this->components->error('Could not reach GitHub to check for a newer release.');

            return self::FAILURE;
        }

        if (version_compare($latest['version'], $current, '<=')) {
            $this->components->info("TaskR is already up to date (v{$current}).");

            return self::SUCCESS;
        }

        $this->components->info("A newer release is available: v{$current} → v{$latest['version']}.");
        $this->line("  <fg=green>➜</> {$latest['url']}");

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if (! $this->confirmUpdate($latest['version'], $interactive)) {
            $this->components->info('No changes were made. Re-run `taskr:update` any time.');

            return self::SUCCESS;
        }

        $this->components->warn('Back up your database before continuing (e.g. pg_dump) — this overwrites the application files in place.');

        try {
            $written = $this->updater->applyRelease($latest['tarballUrl'], $path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Applied v{$latest['version']} — {$written} files updated.");

        if (is_file($path.'/bootstrap/cache/config.php')) {
            $this->call('config:clear');
        }

        $composerRan = $this->runStep($interactive, 'Run `composer install` now?', fn (): bool => $this->runComposerInstall($path));
        $npmRan = $this->runStep($interactive, 'Install npm dependencies and rebuild the frontend now?', fn (): bool => $this->runNpmBuild($path));
        $migrated = $this->runStep($interactive, 'Run the database migrations now?', fn (): bool => $this->call('migrate', ['--force' => true]) === self::SUCCESS);

        $this->renderNextSteps($composerRan, $npmRan, $migrated);

        return self::SUCCESS;
    }

    private function confirmUpdate(string $version, bool $interactive): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $interactive) {
            $this->components->warn('Not running interactively — pass --force to apply the update without a prompt.');

            return false;
        }

        return $this->confirm("Update TaskR to v{$version} now?", true);
    }

    /**
     * Runs a post-update step: asked for confirmation when interactive
     * (default yes), run automatically with --force otherwise. Returns
     * whether the step actually ran.
     */
    private function runStep(bool $interactive, string $question, callable $run): bool
    {
        if ($interactive) {
            if (! $this->confirm($question, true)) {
                return false;
            }

            return (bool) $run();
        }

        if ($this->option('force')) {
            return (bool) $run();
        }

        return false;
    }

    private function runComposerInstall(string $path): bool
    {
        $composer = (new ExecutableFinder)->find('composer', null, [getcwd(), $path]);

        if ($composer === null) {
            $this->components->warn('composer was not found on this machine — run `composer install` manually.');

            return false;
        }

        return $this->runProcess([$composer, 'install'], $path, 'composer install');
    }

    private function runNpmBuild(string $path): bool
    {
        $npm = (new ExecutableFinder)->find('npm', null, [getcwd(), $path]);

        if ($npm === null) {
            $this->components->warn('npm was not found on this machine — run `npm install && npm run build` manually.');

            return false;
        }

        return $this->runProcess([$npm, 'install', '--ignore-scripts'], $path, 'npm install')
            && $this->runProcess([$npm, 'run', 'build'], $path, 'npm run build');
    }

    /** @param array<int, string> $command */
    private function runProcess(array $command, string $path, string $label): bool
    {
        $process = new Process($command, $path, null, null, null);

        $exit = $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if ($exit !== 0) {
            $this->components->warn("{$label} failed — run it manually.");

            return false;
        }

        return true;
    }

    private function renderNextSteps(bool $composerRan, bool $npmRan, bool $migrated): void
    {
        $steps = array_filter([
            $composerRan ? null : 'composer install',
            $npmRan ? null : 'npm install && npm run build',
            $migrated ? null : 'php artisan migrate --force',
        ]);

        if ($steps === []) {
            $this->components->info('TaskR is up to date — nothing left to run.');

            return;
        }

        $this->newLine();
        $this->components->info('Remaining steps before serving the updated app:');

        foreach ($steps as $step) {
            $this->line("  <fg=green>➜</> {$step}");
        }
    }
}
