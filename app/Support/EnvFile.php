<?php

namespace App\Support;

use RuntimeException;

/**
 * Surgical .env file editor: loads an env file into memory, updates single
 * keys in place (uncommenting a commented line when needed, commenting an
 * active one out) and writes the result back without touching any other
 * line — comments, blank lines and untouched keys survive byte for byte.
 *
 * The in-memory mutations only hit the file when save() is called, so an
 * aborted edit leaves the original content intact.
 */
class EnvFile
{
    /**
     * Parsed lines; a trailing newline is preserved as a final empty element.
     *
     * @var array<int, string>
     */
    private array $lines = [];

    private function __construct(
        private readonly string $path,
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Env file not found: {$path}");
        }

        $file = new self($path);
        $file->lines = explode("\n", (string) file_get_contents($path));

        return $file;
    }

    /**
     * Active value of the key (comments stripped, quotes unwrapped), or null
     * when the key is commented out or absent.
     */
    public function get(string $key): ?string
    {
        return $this->find($key, commented: false) !== null
            ? $this->valueOf($this->find($key, commented: false))
            : null;
    }

    /**
     * Value of the key whether the line is active or commented out — used to
     * keep defaults and merged values (e.g. COMPOSE_PROFILES) across comments.
     */
    public function peek(string $key): ?string
    {
        return ($line = $this->find($key, commented: null)) !== null
            ? $this->valueOf($line)
            : null;
    }

    /**
     * Set a key: replaces the first active occurrence in place, or uncomments
     * the commented one; appends at the end when the key does not exist.
     * Values with characters outside the bare set are single-quoted (dotenv
     * treats single quotes as literal, so no variable interpolation).
     */
    public function set(string $key, string $value): self
    {
        $encoded = preg_match('/^[A-Za-z0-9_.:@\/+=,-]*$/', $value)
            ? $value
            : "'".str_replace("'", "'\\''", $value)."'";

        return $this->replaceLine($key, "{$key}={$encoded}");
    }

    /**
     * Comment an active key out (no-op when already commented or absent).
     */
    public function commentOut(string $key): self
    {
        $index = $this->findIndex($key, commented: false);

        if ($index !== null) {
            $this->lines[$index] = '# '.ltrim($this->lines[$index]);
        }

        return $this;
    }

    public function save(): self
    {
        file_put_contents($this->path, implode("\n", $this->lines), LOCK_EX);

        return $this;
    }

    /**
     * First line matching the key. With $commented = null, commented lines
     * match too (active occurrences win when both exist).
     */
    private function find(string $key, ?bool $commented): ?string
    {
        return ($index = $this->findIndex($key, $commented)) !== null
            ? $this->lines[$index]
            : null;
    }

    private function findIndex(string $key, ?bool $commented): ?int
    {
        $pattern = '/^(\s*)(#\s*)?'.preg_quote($key, '/').'(\s*=).*/';

        foreach ($this->lines as $index => $line) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $isCommented = $matches[2] !== '';

            if ($commented === null || $isCommented === $commented) {
                return $index;
            }
        }

        return null;
    }

    private function replaceLine(string $key, string $replacement): self
    {
        $index = $this->findIndex($key, commented: null);

        if ($index !== null) {
            // Preserve the original indentation.
            preg_match('/^\s*/', $this->lines[$index], $matches);
            $this->lines[$index] = ($matches[0] ?? '').$replacement;
        } elseif ($this->lines !== [] && end($this->lines) === '') {
            // Keep a trailing newline last.
            array_splice($this->lines, -1, 0, [$replacement]);
        } else {
            $this->lines[] = $replacement;
        }

        return $this;
    }

    /**
     * Extract the raw value from a KEY=value line, unwrapping quotes.
     */
    private function valueOf(string $line): ?string
    {
        if (preg_match('/^[^=]*=\s*(.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        if (preg_match('/^(["\'])(.*)\1$/', $value, $quotes) === 1) {
            $value = $quotes[2];
        }

        return $value;
    }
}
