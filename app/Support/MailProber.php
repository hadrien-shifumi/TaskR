<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a real test e-mail during installation through a temporary SMTP
 * configuration, restored right after — it never touches the application's
 * persisted mail config. Kept as a thin, stateless service so tests can
 * swap it out for a fake.
 */
class MailProber
{
    /**
     * @param  array{host: string, port: string|int, username: string, password: string, from_address: string, from_name: string}  $parameters
     * @return array{ok: bool, message: string}
     */
    public function probe(array $parameters, string $to): array
    {
        $previousMailer = config('mail.default');
        $previousSmtp = config('mail.mailers.smtp');
        $previousFrom = config('mail.from');

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', array_merge((array) $previousSmtp, [
            'transport' => 'smtp',
            'host' => $parameters['host'],
            'port' => (int) $parameters['port'],
            'username' => $parameters['username'] !== '' ? $parameters['username'] : null,
            'password' => $parameters['password'] !== '' ? $parameters['password'] : null,
        ]));
        config()->set('mail.from', [
            'address' => $parameters['from_address'],
            'name' => $parameters['from_name'],
        ]);

        try {
            Mail::raw('This is a test e-mail sent by the TaskR installer.', function (Message $message) use ($to): void {
                $message->to($to)->subject('TaskR installer — test e-mail');
            });

            return ['ok' => true, 'message' => "Test e-mail sent to {$to}."];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } finally {
            config()->set('mail.default', $previousMailer);
            config()->set('mail.mailers.smtp', $previousSmtp);
            config()->set('mail.from', $previousFrom);
        }
    }
}
