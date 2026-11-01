<?php

namespace App\Jobs;

use App\Actions\Notifications\BuildDigest;
use App\Mail\DigestMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DigestContent;
use App\Support\DigestContext;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Build and send the digest of one account, in its primary workspace.
 *
 * The only writer of notification_preferences.last_digest_at: an empty
 * digest sends nothing and leaves the date untouched, a sent digest stamps
 * it. `now` is expressed in the account timezone so every date bound of
 * the aggregation follows the user's calendar.
 */
class SendAccountDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public User $user) {}

    public function handle(): void
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $this->user->getKey())
            ->first();

        if ($preference === null || ! $preference->enabled) {
            return;
        }

        $workspace = $this->primaryWorkspace();

        if ($workspace === null) {
            return;
        }

        $context = new DigestContext(
            user: $this->user,
            workspace: $workspace,
            preference: $preference,
            now: CarbonImmutable::now($preference->timezone),
        );

        $content = $this->build($context);

        if ($content->isEmpty()) {
            return;
        }

        Mail::to($this->user)
            ->locale($this->user->locale)
            ->send(new DigestMail($content, $context));

        $preference->forceFill(['last_digest_at' => now()])->save();
    }

    /**
     * Aggregate with the primary workspace bound: the aggregator's queries
     * are filtered by the workspace context, which a queued job does not
     * inherit from any request. The previous context is restored so a
     * long-lived worker never leaks it into the next job.
     */
    private function build(DigestContext $context): DigestContent
    {
        $workspaceContext = app(WorkspaceContext::class);
        $previous = $workspaceContext->get();
        $workspaceContext->set($context->workspace);

        try {
            return app(BuildDigest::class)->handle($context);
        } finally {
            $workspaceContext->set($previous);
        }
    }

    /**
     * Primary workspace of the account: the first by name among its
     * memberships, the same fallback as ResolveCurrentWorkspace has
     * outside a session.
     */
    private function primaryWorkspace(): ?Workspace
    {
        return Workspace::query()
            ->whereHas('memberships', fn ($membership) => $membership
                ->where('user_id', $this->user->getKey()))
            ->orderBy('name')
            ->first();
    }
}
