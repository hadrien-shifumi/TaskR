<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Free-text search over issues, shared by every listing surface (the
 * workspace issues page and the project board / list). The title search
 * always applies, raw (historical behavior). When the text looks like a
 * ULID — complete or a prefix of at least seven characters from the
 * Crockford alphabet — the identifier is matched too, so the value of the
 * "copy identifier" button can be pasted back. LIKE wildcards of the
 * identifier branch are escaped.
 */
class IssueSearch
{
    /**
     * Crockford base32 alphabet of a ULID, without the ambiguous I, L, O and U.
     * A search text made only of these characters may be an identifier.
     */
    private const ULID_ALPHABET = '0-9A-HJKMNP-TV-Z';

    /** Shortest identifier prefix the search resolves to a ticket. */
    public const ULID_PREFIX_MIN_LENGTH = 7;

    /**
     * Constrain the query to the issues whose title contains the search —
     * or whose identifier starts with it when it looks like a ULID.
     */
    public static function apply(Builder $query, string $search): void
    {
        $query->where(function (Builder $matches) use ($search): void {
            $matches->where('issues.title', 'like', '%'.$search.'%');

            $identifier = strtoupper($search);

            if (! self::looksLikeUlid($identifier)) {
                return;
            }

            $matches->orWhere('issues.id', 'like', self::escapeLike($identifier).'%');
        });
    }

    /**
     * A complete ULID (26 characters) or a prefix of at least seven
     * characters, made only of Crockford base32 characters.
     */
    private static function looksLikeUlid(string $identifier): bool
    {
        $length = strlen($identifier);

        if ($length < self::ULID_PREFIX_MIN_LENGTH || $length > 26) {
            return false;
        }

        return preg_match('/^['.self::ULID_ALPHABET.']+$/', $identifier) === 1;
    }

    /**
     * Escape the LIKE wildcards so a pasted identifier matches literally.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
