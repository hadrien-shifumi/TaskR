<?php

namespace App\Mail;

use App\Presenters\Notifications\DigestPresenter;
use App\Support\DigestContent;
use App\Support\DigestContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Transactional digest e-mail of one account in one workspace.
 *
 * Plain HTML, no CSS framework: the sections come from the shared digest
 * presenter, the subject and copy from the mail translation files, so the
 * mail speaks the account language.
 */
class DigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var array<string, mixed>
     */
    public readonly array $digest;

    /**
     * @param  array<string, mixed>|null  $digest  DigestPresenter::present() payload, computed when omitted
     */
    public function __construct(
        public readonly DigestContent $content,
        public readonly DigestContext $context,
        ?array $digest = null,
    ) {
        $this->digest = $digest ?? DigestPresenter::present($content, $context);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.digest.subject', ['workspace' => $this->context->workspace->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.digest',
            with: [
                'user' => $this->context->user,
                'workspace' => $this->context->workspace,
                'digest' => $this->digest,
            ],
        );
    }
}
