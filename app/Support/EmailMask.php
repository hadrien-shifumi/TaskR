<?php

namespace App\Support;

/**
 * Partially masks an email address for display: the first character of the
 *
 * local part, then asterisks, with the domain left readable (a***@domain.tld).
 * The recipient of an invitation already knows the full address, so the mask
 * only prevents third parties from reading it over their shoulder.
 */
class EmailMask
{
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
