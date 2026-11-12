<?php

namespace Tests\Feature;

use App\Support\DatabaseProber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * taskr:install — the interactive installer: asks for the database setup and
 * the embeddings provider, writes them into the .env file (preserving every
 * untouched line), generates the APP_KEY when missing and defers or offers
 * the migrations depending on the chosen mode.
 */
class InstallTaskrCommandTest extends TestCase
{
    private string $envDir;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the install target: a temp .env copied from the real
        // .env.example, wired as the application's environment file so the
        // command never touches the developer's .env.
        $this->envDir = sys_get_temp_dir().'/taskr-install-test-'.uniqid();
        mkdir($this->envDir, 0777, true);
        $this->envPath = $this->envDir.'/.env';
        copy(base_path('.env.example'), $this->envPath);

        $this->app->useEnvironmentPath($this->envDir);
        $this->app->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        @rmdir($this->envDir);

        parent::tearDown();
    }

    /**
     * @param  array{ok: bool, pgvector?: bool, message?: string}  $result
     */
    private function fakeProber(array $result): void
    {
        $result = ['ok' => $result['ok'], 'pgvector' => $result['pgvector'] ?? false, 'message' => $result['message'] ?? 'fake probe'];

        $prober = new class($result) extends DatabaseProber
        {
            /** @param array{ok: bool, pgvector: bool, message: string} $result */
            public function __construct(private readonly array $result) {}

            public function probe(array $parameters): array
            {
                return $this->result;
            }
        };

        $this->instance(DatabaseProber::class, $prober);
    }

    /** Fake embeddings response; defaults to the Mistral preset dimensions. */
    private function fakeEmbeddingResponse(int $dimensions = 1024): void
    {
        Http::fake([
            'api.mistral.ai/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, $dimensions, 0.1)],
                ],
            ]),
        ]);
    }

    public function test_local_dev_mode_with_mistral_preset_configures_env_embeddings_and_app_key(): void
    {
        $this->fakeProber(['ok' => true, 'pgvector' => true]);
        $this->fakeEmbeddingResponse();

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'local')
            ->expectsQuestion('Database name', 'taskr')
            ->expectsQuestion('Database user', 'postgres')
            ->expectsQuestion('Database password (leave empty to keep the current one)', 'root')
            ->expectsConfirmation('Test the database connection now?', 'yes')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'mistral')
            ->expectsQuestion('Mistral API key', 'sk-mistral-test')
            ->expectsConfirmation('Test the embeddings endpoint now?', 'yes')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->expectsConfirmation('Run the database migrations now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString("\nDB_HOST=127.0.0.1\n", $env);
        $this->assertStringContainsString("\nDB_PORT=5433\n", $env);
        $this->assertMatchesRegularExpression('/^COMPOSE_PROFILES=.*bundled-db/m', $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_BASE_URL=https://api.mistral.ai/v1\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_MODEL=mistral-embed\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_DIMENSIONS=1024\n", $env);
        $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_API_KEY=', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $env);
        // Untouched lines survive.
        $this->assertStringContainsString('APP_NAME=TaskR', $env);
    }

    public function test_bundled_docker_mode_keeps_db_host_commented_and_defers_migrations(): void
    {
        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->expectsOutputToContain('Migrations will run automatically at container boot.')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertMatchesRegularExpression('/^# DB_HOST=/m', $env);
        $this->assertMatchesRegularExpression('/^# DB_PORT=/m', $env);
        $this->assertMatchesRegularExpression('/^COMPOSE_PROFILES=.*bundled-db/m', $env);
        $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_BASE_URL=', $env);
        $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_MODEL=', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:/m', $env);
    }

    public function test_external_database_mode_writes_credentials_and_comments_out_compose_profiles(): void
    {
        $this->fakeProber(['ok' => true, 'pgvector' => true]);

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'external')
            ->expectsQuestion('Database host', 'db.example.com')
            ->expectsQuestion('Database port', '5432')
            ->expectsQuestion('Database name', 'taskr_prod')
            ->expectsQuestion('Database user', 'taskr')
            ->expectsQuestion('Database password (leave empty to keep the current one)', 's3cr3t!')
            ->expectsConfirmation('Test the database connection now?', 'yes')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Configure for a public/production deployment?', 'yes')
            ->expectsQuestion('Application URL', 'https://taskr.example.com')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->expectsConfirmation('Run the database migrations now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString("\nDB_HOST=db.example.com\n", $env);
        $this->assertStringContainsString("\nDB_PORT=5432\n", $env);
        $this->assertStringContainsString("\nDB_DATABASE=taskr_prod\n", $env);
        $this->assertStringContainsString("\nDB_USERNAME=taskr\n", $env);
        $this->assertStringContainsString("DB_PASSWORD='s3cr3t!'", $env);
        $this->assertMatchesRegularExpression('/^# COMPOSE_PROFILES=/m', $env);
        $this->assertStringContainsString("\nAPP_ENV=production\n", $env);
        $this->assertStringContainsString("\nAPP_DEBUG=false\n", $env);
        $this->assertStringContainsString("\nAPP_URL=https://taskr.example.com\n", $env);
    }

    public function test_failing_database_probe_warns_and_never_offers_migrations(): void
    {
        $this->fakeProber(['ok' => false, 'message' => 'connection refused']);

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'external')
            ->expectsQuestion('Database host', 'db.example.com')
            ->expectsQuestion('Database port', '5432')
            ->expectsQuestion('Database name', 'taskr')
            ->expectsQuestion('Database user', 'postgres')
            ->expectsQuestion('Database password (leave empty to keep the current one)', 'secret')
            ->expectsConfirmation('Test the database connection now?', 'yes')
            ->expectsOutputToContain('connection refused')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString("\nDB_HOST=db.example.com\n", $env);
        $this->assertMatchesRegularExpression('/^# COMPOSE_PROFILES=/m', $env);
    }

    public function test_embeddings_dimension_mismatch_is_reported_as_a_warning(): void
    {
        $this->fakeEmbeddingResponse(3);

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'mistral')
            ->expectsQuestion('Mistral API key', 'sk-mistral-test')
            ->expectsConfirmation('Test the embeddings endpoint now?', 'yes')
            ->expectsOutputToContain('KNOWLEDGE_EMBEDDINGS_DIMENSIONS')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_DIMENSIONS=1024\n", $env);
    }

    public function test_declining_the_recap_leaves_the_env_file_untouched(): void
    {
        $before = file_get_contents($this->envPath);

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'no')
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->envPath), 'Declining the recap must not write anything.');
    }

    public function test_custom_preset_asks_for_and_writes_every_setting(): void
    {
        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'custom')
            ->expectsQuestion('Embeddings endpoint base URL', 'http://localhost:8000/v1')
            ->expectsQuestion('Embeddings model', 'qwen3-embedding')
            ->expectsQuestion('Embeddings vector dimensions', '1024')
            ->expectsQuestion('Embeddings API key (optional)', 'local-key')
            ->expectsConfirmation('Test the embeddings endpoint now?', 'no')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_BASE_URL=http://localhost:8000/v1\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_MODEL=qwen3-embedding\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_DIMENSIONS=1024\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_API_KEY=local-key\n", $env);
    }

    public function test_choosing_none_clears_an_existing_embeddings_configuration_when_confirmed(): void
    {
        file_put_contents($this->envPath, str_replace(
            ["KNOWLEDGE_EMBEDDINGS_BASE_URL=\n", "KNOWLEDGE_EMBEDDINGS_API_KEY=\n", "KNOWLEDGE_EMBEDDINGS_MODEL=\n"],
            ["KNOWLEDGE_EMBEDDINGS_BASE_URL=https://api.mistral.ai/v1\n", "KNOWLEDGE_EMBEDDINGS_API_KEY=old-key\n", "KNOWLEDGE_EMBEDDINGS_MODEL=mistral-embed\n"],
            (string) file_get_contents($this->envPath),
        ));

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Clear the existing embeddings configuration?', 'yes')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_BASE_URL=\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_API_KEY=\n", $env);
        $this->assertStringContainsString("\nKNOWLEDGE_EMBEDDINGS_MODEL=\n", $env);
    }

    public function test_existing_app_key_is_kept_unless_regeneration_is_confirmed(): void
    {
        file_put_contents($this->envPath, str_replace(
            "APP_KEY=\n",
            "APP_KEY=base64:existingkeyexistingkeyexistingkeyexist=\n",
            (string) file_get_contents($this->envPath),
        ));

        $this->artisan('taskr:install')
            ->expectsQuestion('Where will the database run?', 'bundled')
            ->expectsQuestion('Embeddings provider for the knowledge base semantic search?', 'none')
            ->expectsConfirmation('Configure for a public/production deployment?', 'no')
            ->expectsConfirmation('Generate a new application key?', 'no')
            ->expectsConfirmation('Write these settings to the .env file?', 'yes')
            ->expectsConfirmation('Install npm dependencies and build the frontend now?', 'no')
            ->assertSuccessful();

        $this->assertStringContainsString(
            "APP_KEY=base64:existingkeyexistingkeyexistingkeyexist=\n",
            (string) file_get_contents($this->envPath),
        );
    }

    public function test_non_interactive_run_applies_bundled_defaults_and_generates_the_app_key(): void
    {
        $this->artisan('taskr:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Migrations will run automatically at container boot.')
            ->assertSuccessful();

        $env = file_get_contents($this->envPath);

        $this->assertMatchesRegularExpression('/^COMPOSE_PROFILES=.*bundled-db/m', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:/m', $env);
        // Non-interactive keeps the detected setup untouched otherwise.
        $this->assertMatchesRegularExpression('/^# DB_HOST=/m', $env);
        $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_BASE_URL=', $env);
    }
}
