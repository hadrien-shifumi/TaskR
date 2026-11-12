<?php

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Probes a PostgreSQL connection during installation: checks the credentials
 * and the pgvector extension required by the knowledge base migrations,
 * attempting to create the extension when it is missing. Kept as a thin,
 * stateless service so tests can swap it out for a fake.
 */
class DatabaseProber
{
    /**
     * @param  array{host: string, port: string|int, database: string, username: string, password: string}  $parameters
     * @return array{ok: bool, pgvector: bool, message: string}
     */
    public function probe(array $parameters): array
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=prefer',
            $parameters['host'],
            (int) $parameters['port'],
            $parameters['database'],
        );

        try {
            $pdo = new PDO($dsn, $parameters['username'], $parameters['password'], [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $exception) {
            return ['ok' => false, 'pgvector' => false, 'message' => $exception->getMessage()];
        }

        if ($this->hasPgvector($pdo)) {
            return ['ok' => true, 'pgvector' => true, 'message' => 'pgvector extension is ready.'];
        }

        // The extension may be available but not enabled — try to enable it.
        try {
            $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector;');

            if ($this->hasPgvector($pdo)) {
                return ['ok' => true, 'pgvector' => true, 'message' => 'pgvector extension was created.'];
            }
        } catch (RuntimeException|PDOException) {
            // Fall through to the missing-extension report below.
        }

        return ['ok' => true, 'pgvector' => false, 'message' => 'The pgvector extension is missing and could not be created automatically — run CREATE EXTENSION IF NOT EXISTS vector; on the target database (requires sufficient privileges), see the README.'];
    }

    private function hasPgvector(PDO $pdo): bool
    {
        try {
            $statement = $pdo->query("SELECT 1 FROM pg_extension WHERE extname = 'vector'");

            return (bool) $statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }
}
