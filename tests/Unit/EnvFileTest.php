<?php

namespace Tests\Unit;

use App\Support\EnvFile;
use Tests\TestCase;

/**
 * EnvFile — surgical .env editor: reads an env file, updates single keys in
 * place (uncommenting commented ones when needed) and writes the result back
 * without touching any other line.
 */
class EnvFileTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'envfile-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    private function writeEnv(string $content): EnvFile
    {
        file_put_contents($this->envPath, $content);

        return EnvFile::load($this->envPath);
    }

    public function test_get_returns_the_active_value_and_unquotes_it(): void
    {
        $env = $this->writeEnv(<<<'ENV'
            APP_NAME=TaskR
            MAIL_FROM_NAME="${APP_NAME}"
            APP_URL=http://localhost:8080
            # DB_HOST=127.0.0.1
            ENV);

        $this->assertSame('TaskR', $env->get('APP_NAME'));
        $this->assertSame('${APP_NAME}', $env->get('MAIL_FROM_NAME'));
        $this->assertSame('http://localhost:8080', $env->get('APP_URL'));
        $this->assertNull($env->get('DB_HOST'), 'A commented key must read as null.');
        $this->assertNull($env->get('DOES_NOT_EXIST'));
        $this->assertSame('127.0.0.1', $env->peek('DB_HOST'), 'peek() must read through comments (kept for re-merging).');
        $this->assertNull($env->peek('DOES_NOT_EXIST'));
    }

    public function test_set_replaces_an_existing_value_in_place_preserving_every_other_line(): void
    {
        $original = <<<'ENV'
            APP_NAME=TaskR
            APP_ENV=local

            # A comment block
            APP_URL=http://localhost:8080
            DB_DATABASE=taskr

            KNOWLEDGE_EMBEDDINGS_MODEL=mistral-embed
            ENV;

        $env = $this->writeEnv($original);
        $env->set('APP_URL', 'https://taskr.example.com');
        $env->save();

        $expected = <<<'ENV'
            APP_NAME=TaskR
            APP_ENV=local

            # A comment block
            APP_URL=https://taskr.example.com
            DB_DATABASE=taskr

            KNOWLEDGE_EMBEDDINGS_MODEL=mistral-embed
            ENV;

        $this->assertSame($expected, file_get_contents($this->envPath));
    }

    public function test_set_uncomments_a_previously_commented_key(): void
    {
        $env = $this->writeEnv(<<<'ENV'
            # COMPOSE_PROFILES=bundled-db

            # DB_HOST=127.0.0.1
            DB_PORT=5433
            ENV);

        $env->set('DB_HOST', '10.0.0.5');
        $env->save();

        $this->assertSame(<<<'ENV'
            # COMPOSE_PROFILES=bundled-db

            DB_HOST=10.0.0.5
            DB_PORT=5433
            ENV, file_get_contents($this->envPath));
    }

    public function test_set_appends_a_missing_key_at_the_end(): void
    {
        $env = $this->writeEnv("APP_NAME=TaskR\n");
        $env->set('DB_PASSWORD', 'secret');
        $env->save();

        $this->assertSame("APP_NAME=TaskR\nDB_PASSWORD=secret\n", file_get_contents($this->envPath));
    }

    public function test_set_single_quotes_values_with_special_characters(): void
    {
        $env = $this->writeEnv("DB_PASSWORD=old\n");
        $env->set('DB_PASSWORD', 'pa ss#1');
        $env->save();

        $this->assertSame("DB_PASSWORD='pa ss#1'\n", file_get_contents($this->envPath));
        $this->assertSame('pa ss#1', EnvFile::load($this->envPath)->get('DB_PASSWORD'), 'get() must unquote what set() wrote.');
    }

    public function test_comment_out_prefixes_the_active_line_only(): void
    {
        $original = <<<'ENV'
            # COMPOSE_PROFILES=bundled-db
            DB_HOST=127.0.0.1
            APP_NAME=TaskR
            ENV;

        $env = $this->writeEnv($original);

        // Already commented → untouched.
        $env->commentOut('COMPOSE_PROFILES');
        // Absent → no-op.
        $env->commentOut('DOES_NOT_EXIST');
        // Active → commented out.
        $env->commentOut('DB_HOST');
        $env->save();

        $this->assertSame(<<<'ENV'
            # COMPOSE_PROFILES=bundled-db
            # DB_HOST=127.0.0.1
            APP_NAME=TaskR
            ENV, file_get_contents($this->envPath));
    }
}
