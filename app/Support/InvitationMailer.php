<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Project;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the invitation e-mail to the invited address. Shared by the invite
 * (creation and reactivation) and resend flows so every fresh acceptance
 * link reaches the invitee by mail — not only through the owner's flash.
 *
 * The mail speaks the current request locale: the inviter's preference on
 * web, the application default elsewhere (MCP, queue).
 */
class InvitationMailer
{
    public static function send(Invitation $invitation): void
    {
        $workspace = $invitation->workspace;

        $inviter = $workspace?->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->first()
            ?->user;

        // The acceptance route has no workspace context: read the announced
        // projects through the unscoped pivot and the project names without
        // the global scope, exactly like the acceptance landing page. Each
        // project line carries the role the invitee lands with.
        $announced = InvitationProject::query()
            ->where('invitation_id', $invitation->getKey())
            ->get();

        $projectNames = $announced->isEmpty()
            ? []
            : Project::withoutWorkspaceScope()
                ->whereIn('id', $announced->pluck('project_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): string => $project->name.' ('.__('mail.invitation.project_roles.'.$announced->firstWhere('project_id', $project->id)->role->value).')')
                ->all();

        try {
            Mail::to($invitation->email)
                ->locale(App::getLocale())
                ->send(new InvitationMail(
                    email: $invitation->email,
                    role: $invitation->role->value,
                    workspaceName: $workspace?->name ?? '',
                    inviterName: $inviter?->name ?? $workspace?->name ?? '',
                    projectNames: $projectNames,
                    acceptUrl: route('invitations.accept', ['invitation' => $invitation->token]),
                    expiresAt: $invitation->expires_at,
                ));
        } catch (Throwable $e) {
            // The invitation row is already committed: a mail failure (bad
            // sender address, SMTP down) must not turn the HTTP call into a
            // 500. The owner still gets the acceptance link in the flash;
            // the failure is logged so the mailer config gets fixed.
            Log::error('Invitation mail could not be sent.', [
                'invitation_id' => $invitation->getKey(),
                'workspace_id' => $invitation->workspace_id,
                'email' => $invitation->email,
                'exception' => $e,
            ]);
        }
    }
}
