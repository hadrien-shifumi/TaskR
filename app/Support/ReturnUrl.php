<?php

namespace App\Support;

/**
 * Validates the return target that guest pages (invitations…) hand over to
 * the auth forms through the "redirect" query parameter. Only same-app
 * paths are honored: an external URL would turn the auth redirect into an
 * open redirect, so anything not starting with a single "/" is dropped.
 */
class ReturnUrl
{
    public static function sanitize(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? $url
            : null;
    }
}
