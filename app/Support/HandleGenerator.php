<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;

/**
 * Derives mention handles from display names.
 *
 * A handle is a lowercase slug of the name (max 32 chars); collisions
 * append -2, -3… User handles are globally unique, project handles are
 * unique per workspace. Unusable names fall back to a generic handle.
 */
class HandleGenerator
{
    private const MAX_LENGTH = 32;

    public static function forUser(string $name): string
    {
        return self::unique($name, 'user', fn (string $handle): bool => User::query()->where('handle', $handle)->exists());
    }

    public static function forProject(string $name, string $workspaceId): string
    {
        return self::unique($name, 'project', fn (string $handle): bool => Project::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('handle', $handle)
            ->exists());
    }

    /**
     * @param  Closure(string): bool  $exists  Whether the candidate handle is already taken.
     */
    private static function unique(string $name, string $fallback, Closure $exists): string
    {
        $base = Str::slug($name);

        if ($base === '' || $base === '0') {
            $base = $fallback;
        }

        $base = substr($base, 0, self::MAX_LENGTH);
        $handle = $base;
        $suffix = 1;

        while ($exists($handle)) {
            $suffix++;
            $stem = substr($base, 0, max(1, self::MAX_LENGTH - strlen((string) $suffix) - 1));
            $handle = $stem.'-'.$suffix;
        }

        return $handle;
    }
}
