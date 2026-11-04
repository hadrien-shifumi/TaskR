<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset link e-mail: carries the one-hour, single-use token as a
 * signed link to the reset form. The copy comes from the mail translation
 * files so the mail speaks the account language.
 */
class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.reset.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reset',
            with: [
                'user' => $this->user,
                'resetUrl' => route('password.reset', [
                    'token' => $this->token,
                    'email' => $this->user->email,
                ]),
            ],
        );
    }
}
