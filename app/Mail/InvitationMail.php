<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Workspace invitation e-mail: carries the acceptance link addressed to the
 * invited address (which may not have an account yet, hence scalar payload
 * instead of models — the Invitation global scope would not resolve inside
 * a queued worker). The copy comes from the mail translation files so the
 * mail speaks the inviter's language (the request locale).
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $projectNames
     */
    public function __construct(
        public readonly string $email,
        public readonly string $role,
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly array $projectNames,
        public readonly string $acceptUrl,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.invitation.subject', ['workspace' => $this->workspaceName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitation',
            with: [
                'email' => $this->email,
                'roleLabel' => __('mail.invitation.roles.'.$this->role),
                'workspaceName' => $this->workspaceName,
                'inviterName' => $this->inviterName,
                'projectNames' => $this->projectNames,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
