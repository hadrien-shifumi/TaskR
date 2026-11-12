<?php

namespace App\Console\Commands;

use App\Enums\EmbeddingPreset;
use App\Support\DatabaseProber;
use App\Support\EmbeddingDimensionMismatch;
use App\Support\Embeddings;
use App\Support\EnvFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\RequestException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[Signature('taskr:install')]
#[Description('Interactive installer: configure the database mode, the embeddings provider and the app key in .env, then optionally migrate and build the frontend.')]
class InstallTaskr extends Command
{
    /** @var array<string, string> */
    private const DATABASE_MODES = [
        'bundled' => 'Docker — bundled PostgreSQL (compose profile bundled-db)',
        'local' => 'Local development — app on the host, bundled PostgreSQL (127.0.0.1:5433)',
        'external' => 'External / managed PostgreSQL',
    ];

    public function __construct(private readonly DatabaseProber $prober)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $example = base_path('.env.example');

            if (! is_file($example)) {
                $this->components->error('No .env file and no .env.example to start from.');

                return self::FAILURE;
            }

            copy($example, $path);
        }

        $env = EnvFile::load($path);
        $interactive = $this->input->isInteractive();

        $this->components->info('Welcome to TaskR! This wizard writes the database, embeddings and application settings to your .env file.');

        // --- Database -----------------------------------------------------
        $mode = $interactive
            ? $this->choice('Where will the database run?', self::DATABASE_MODES, $this->detectDatabaseMode($env))
            : $this->detectDatabaseMode($env);

        /** @var array{host: string, port: string, database: string, username: string, password: string} $database */
        $database = match ($mode) {
            'external' => [
                'host' => (string) ($this->ask('Database host', $env->peek('DB_HOST') ?? '127.0.0.1')),
                'port' => (string) ($this->ask('Database port', $env->peek('DB_PORT') ?? '5432')),
                'database' => (string) $this->ask('Database name', $env->peek('DB_DATABASE') ?? 'taskr'),
                'username' => (string) $this->ask('Database user', $env->peek('DB_USERNAME') ?? 'postgres'),
                'password' => (string) ($this->secret('Database password (leave empty to keep the current one)') ?? ''),
            ],
            'local' => [
                'host' => '127.0.0.1',
                'port' => '5433',
                'database' => (string) $this->ask('Database name', $env->peek('DB_DATABASE') ?? 'taskr'),
                'username' => (string) $this->ask('Database user', $env->peek('DB_USERNAME') ?? 'postgres'),
                'password' => (string) ($this->secret('Database password (leave empty to keep the current one)') ?? ''),
            ],
            default => [
                'host' => '',
                'port' => '',
                'database' => (string) ($env->peek('DB_DATABASE') ?? 'taskr'),
                'username' => (string) ($env->peek('DB_USERNAME') ?? 'postgres'),
                'password' => (string) ($env->peek('DB_PASSWORD') ?? 'root'),
            ],
        };

        if ($mode === 'local' || $mode === 'external') {
            $database['password'] = $database['password'] !== ''
                ? $database['password']
                : (string) ($env->peek('DB_PASSWORD') ?? 'root');
        }

        $probe = null;

        if ($interactive && in_array($mode, ['local', 'external'], true) && $this->confirm('Test the database connection now?', true)) {
            $probe = $this->probeDatabase($database);
        }

        // --- Embeddings ---------------------------------------------------
        $preset = $interactive
            ? EmbeddingPreset::from((string) $this->choice('Embeddings provider for the knowledge base semantic search?', EmbeddingPreset::choices(), $this->detectEmbeddingPreset($env)))
            : null;

        $embeddings = null;

        if ($preset !== null && $preset !== EmbeddingPreset::None) {
            $embeddings = [
                'base_url' => $preset->baseUrl() ?? (string) $this->ask('Embeddings endpoint base URL'),
                'model' => $preset->model() ?? (string) $this->ask('Embeddings model'),
                'dimensions' => (string) ($preset->dimensions() ?? $this->ask('Embeddings vector dimensions', '1024')),
                'api_key' => (string) ($preset->defaultApiKey() ?? ($preset === EmbeddingPreset::Custom
                    ? $this->secret('Embeddings API key (optional)')
                    : $this->secret("{$preset->label()} API key")) ?? ''),
            ];
        } elseif ($preset === EmbeddingPreset::None && $this->hasEmbeddingsConfigured($env)) {
            $embeddings = $this->confirm('Clear the existing embeddings configuration?', false)
                ? ['base_url' => '', 'model' => '', 'dimensions' => '', 'api_key' => '']
                : null;
        }

        if ($embeddings !== null && $interactive && $embeddings['base_url'] !== '' && $this->confirm('Test the embeddings endpoint now?', true)) {
            $this->probeEmbeddings($embeddings);
        }

        // --- Application --------------------------------------------------
        $production = $interactive && $this->confirm('Configure for a public/production deployment?', false);

        if ($production) {
            $env->set('APP_ENV', 'production');
            $env->set('APP_DEBUG', 'false');
            $env->set('APP_URL', (string) $this->ask('Application URL', $env->get('APP_URL') ?? 'http://localhost:8080'));
        }

        // --- APP key ------------------------------------------------------
        $keyStatus = 'Kept the existing key.';

        if ($env->get('APP_KEY') === null || $env->get('APP_KEY') === '') {
            $env->set('APP_KEY', $this->generateAppKey());
            $keyStatus = 'Generated a new key.';
        } elseif ($interactive && $this->confirm('Generate a new application key?', false)) {
            $env->set('APP_KEY', $this->generateAppKey());
            $keyStatus = 'Generated a new key.';
        }

        // --- Recap & write ------------------------------------------------
        $this->applyDatabaseMode($env, $mode, $database);

        if ($embeddings !== null) {
            $env->set('KNOWLEDGE_EMBEDDINGS_BASE_URL', $embeddings['base_url']);
            $env->set('KNOWLEDGE_EMBEDDINGS_MODEL', $embeddings['model']);
            $env->set('KNOWLEDGE_EMBEDDINGS_DIMENSIONS', $embeddings['dimensions']);
            $env->set('KNOWLEDGE_EMBEDDINGS_API_KEY', $embeddings['api_key']);
        }

        $this->renderRecap($mode, $database, $preset, $embeddings, $production, $keyStatus);

        if ($interactive && ! $this->confirm('Write these settings to the .env file?', true)) {
            $this->components->info('No changes were written. Re-run taskr:install any time.');

            return self::SUCCESS;
        }

        $env->save();
        $this->components->info("Configuration written to {$path}.");

        // --- Optional post steps -----------------------------------------
        if (is_file($this->laravel->basePath('bootstrap/cache/config.php'))) {
            $this->call('config:clear');
        }

        $npm = (new ExecutableFinder)->find('npm', null, [getcwd(), $this->laravel->basePath()]);

        if ($interactive && $npm !== null && $this->confirm('Install npm dependencies and build the frontend now?', true)) {
            $this->runNpm($npm);
        } elseif ($npm === null) {
            $this->components->warn('npm was not found on this machine — run `npm install --ignore-scripts && npm run build` before serving the app.');
        }

        $migrated = false;

        if ($mode === 'bundled') {
            $this->components->info('Migrations will run automatically at container boot.');
        } elseif ($probe !== null && $probe['ok'] && $probe['pgvector'] && $interactive) {
            if ($this->confirm('Run the database migrations now?', true)) {
                $this->call('migrate', ['--force' => true]);
                $migrated = true;
            }
        } elseif ($probe === null || ! $probe['ok']) {
            $this->components->warn('The database was not reachable — fix the connection and run `php artisan migrate --force` later.');
        } else {
            $this->components->warn('pgvector is missing — create the extension (see the README), then run `php artisan migrate --force`.');
        }

        $this->renderNextSteps($mode, $migrated);

        return self::SUCCESS;
    }

    // --- Database helpers ---------------------------------------------------

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $database
     */
    private function applyDatabaseMode(EnvFile $env, string $mode, array $database): void
    {
        if ($mode === 'external') {
            $env->commentOut('COMPOSE_PROFILES');
            $env->set('DB_HOST', $database['host']);
            $env->set('DB_PORT', $database['port']);
            $env->set('DB_DATABASE', $database['database']);
            $env->set('DB_USERNAME', $database['username']);
            $env->set('DB_PASSWORD', $database['password']);
        } elseif ($mode === 'local') {
            $env->set('COMPOSE_PROFILES', $this->bundledProfiles($env));
            $env->set('DB_HOST', '127.0.0.1');
            $env->set('DB_PORT', '5433');
            $env->set('DB_DATABASE', $database['database']);
            $env->set('DB_USERNAME', $database['username']);
            $env->set('DB_PASSWORD', $database['password']);
        } else {
            $env->set('COMPOSE_PROFILES', $this->bundledProfiles($env));
            $env->commentOut('DB_HOST');
            $env->commentOut('DB_PORT');
        }
    }

    /**
     * bundled-db merged with any already-configured profile (e.g. diun), so
     * enabling the bundled database never drops other compose services.
     */
    private function bundledProfiles(EnvFile $env): string
    {
        $profiles = array_filter(array_map('trim', explode(',', (string) $env->peek('COMPOSE_PROFILES'))));
        $profiles[] = 'bundled-db';

        return implode(',', array_values(array_unique($profiles)));
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $database
     * @return array{ok: bool, pgvector: bool, message: string}
     */
    private function probeDatabase(array $database): array
    {
        $result = $this->prober->probe($database);

        if ($result['ok'] && $result['pgvector']) {
            $this->components->info("Database connection successful — {$result['message']}");
        } elseif ($result['ok']) {
            $this->components->warn("Database reachable, but {$result['message']}");
        } else {
            $this->components->warn("Database connection failed: {$result['message']}");
        }

        return $result;
    }

    // --- Embeddings helpers -------------------------------------------------

    /**
     * @param  array{base_url: string, model: string, dimensions: string, api_key: string}  $embeddings
     */
    private function probeEmbeddings(array $embeddings): void
    {
        $previous = config('knowledge.embeddings');

        config()->set('knowledge.embeddings', [
            'base_url' => $embeddings['base_url'],
            'api_key' => $embeddings['api_key'],
            'model' => $embeddings['model'],
            'dimensions' => (int) $embeddings['dimensions'],
        ]);

        try {
            $vector = app(Embeddings::class)->embed('taskr install probe');

            $this->components->info('Embeddings endpoint successful — responded with a '.count($vector).'-dimensional vector.');
        } catch (RequestException $exception) {
            $this->components->warn('Embeddings endpoint test failed: '.$exception->getMessage());
        } catch (EmbeddingDimensionMismatch $exception) {
            $this->components->warn($exception->getMessage());
        } finally {
            config()->set('knowledge.embeddings', $previous);
        }
    }

    private function detectEmbeddingPreset(EnvFile $env): string
    {
        $baseUrl = $env->get('KNOWLEDGE_EMBEDDINGS_BASE_URL');

        foreach (EmbeddingPreset::cases() as $preset) {
            if ($preset->baseUrl() !== null && $preset->baseUrl() === $baseUrl) {
                return $preset->value;
            }
        }

        return EmbeddingPreset::None->value;
    }

    private function hasEmbeddingsConfigured(EnvFile $env): bool
    {
        return collect(['KNOWLEDGE_EMBEDDINGS_BASE_URL', 'KNOWLEDGE_EMBEDDINGS_MODEL', 'KNOWLEDGE_EMBEDDINGS_API_KEY'])
            ->map(fn (string $key): ?string => $env->get($key))
            ->contains(fn (?string $value): bool => $value !== null && $value !== '');
    }

    // --- Presentation ---------------------------------------------------------

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $database
     * @param  array{base_url: string, model: string, dimensions: string, api_key: string}|null  $embeddings
     */
    private function renderRecap(string $mode, array $database, ?EmbeddingPreset $preset, ?array $embeddings, bool $production, string $keyStatus): void
    {
        $databaseTarget = match ($mode) {
            'bundled' => 'Docker — bundled PostgreSQL (compose profile bundled-db)',
            'local' => "Local — 127.0.0.1:5433/{$database['database']}",
            default => "{$database['host']}:{$database['port']}/{$database['database']}",
        };

        $embeddingsTarget = $embeddings === null
            ? 'Unchanged'
            : ($embeddings['base_url'] === '' ? 'Disabled (semantic search off)' : "{$embeddings['base_url']} — {$embeddings['model']} ({$embeddings['dimensions']} dimensions)");

        $this->table(['Setting', 'Value'], [
            ['Database', $databaseTarget],
            ['DB username', $database['username']],
            ['DB password', $database['password'] === '' ? '(empty)' : str_repeat('●', 8)],
            ['Embeddings', $embeddingsTarget],
            ['Embeddings API key', $embeddings === null || $embeddings['api_key'] === '' ? '(not set)' : str_repeat('●', 8)],
            ['App environment', $production ? 'production (debug off)' : 'unchanged'],
            ['APP_KEY', $keyStatus],
        ]);
    }

    private function renderNextSteps(string $mode, bool $migrationsDeferred): void
    {
        $steps = match ($mode) {
            'bundled' => ['docker compose up -d'],
            'local' => array_filter([
                'docker compose up -d postgres',
                $migrationsDeferred ? 'php artisan migrate --force' : null,
                'composer dev',
            ]),
            default => array_filter([
                $migrationsDeferred ? 'php artisan migrate --force' : null,
                'composer dev',
            ]),
        };

        $this->newLine();
        $this->components->info('TaskR is configured. Next steps:');

        foreach ($steps as $step) {
            $this->line("  <fg=green>➜</> {$step}");
        }

        $this->line('  <fg=green>➜</> Open /register to create your first account.');
    }

    // --- Misc -----------------------------------------------------------------

    private function generateAppKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
    }

    private function detectDatabaseMode(EnvFile $env): string
    {
        $host = $env->get('DB_HOST');

        if ($host === null || $host === '') {
            return 'bundled';
        }

        if (in_array($host, ['127.0.0.1', 'localhost'], true) && (string) $env->get('DB_PORT') === '5433') {
            return 'local';
        }

        return 'external';
    }

    private function runNpm(string $npm): void
    {
        foreach ([[$npm, 'install', '--ignore-scripts'], [$npm, 'run', 'build']] as $command) {
            $process = new Process($command, $this->laravel->basePath(), null, null, null);

            $exit = $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

            if ($exit !== 0) {
                $this->components->warn('A npm step failed — run `npm install && npm run build` manually before serving the app.');

                return;
            }
        }

        $this->components->info('Frontend dependencies installed and assets built.');
    }
}
