<?php

namespace Tests\Unit;

use App\Mail\InvitationMail;
use Tests\TestCase;

class InvitationMailTest extends TestCase
{
    public function test_it_renders_the_acceptance_link_workspace_role_and_projects(): void
    {
        $mail = new InvitationMail(
            email: 'bob@example.com',
            role: 'guest',
            workspaceName: 'Acme',
            inviterName: 'Alice',
            projectNames: ['Alpha', 'Beta'],
            acceptUrl: 'http://localhost:8000/invitations/token123/accept',
            expiresAt: now()->addDays(7),
        );

        $html = $mail->render();

        $this->assertStringContainsString('http://localhost:8000/invitations/token123/accept', $html);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
        $this->assertStringContainsString('Guest', $html);
    }

    public function test_the_subject_names_the_workspace(): void
    {
        $mail = new InvitationMail(
            email: 'bob@example.com',
            role: 'member',
            workspaceName: 'Acme',
            inviterName: 'Alice',
            projectNames: [],
            acceptUrl: 'http://localhost:8000/invitations/token123/accept',
            expiresAt: now()->addDays(7),
        );

        $this->assertStringContainsString('Acme', $mail->envelope()->subject);
    }

    public function test_a_member_invitation_without_projects_renders_no_project_line(): void
    {
        $mail = new InvitationMail(
            email: 'bob@example.com',
            role: 'member',
            workspaceName: 'Acme',
            inviterName: 'Alice',
            projectNames: [],
            acceptUrl: 'http://localhost:8000/invitations/token123/accept',
            expiresAt: now()->addDays(7),
        );

        $html = $mail->render();

        $this->assertStringNotContainsString(__('mail.invitation.projects_line', ['projects' => '']), $html);
    }
}
