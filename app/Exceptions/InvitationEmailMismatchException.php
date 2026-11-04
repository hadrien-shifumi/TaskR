<?php

namespace App\Exceptions;

use App\Support\EmailMask;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An invitation was opened by an account whose email does not match the
 * invitation's destination: the acceptance is refused before any access is
 * created. Rendered as an explicit error page carrying the masked address.
 */
class InvitationEmailMismatchException extends HttpException
{
    public function __construct(string $email)
    {
        parent::__construct(403, __('flash.invitation.email_mismatch', [
            'email' => EmailMask::mask($email),
        ]));
    }
}
