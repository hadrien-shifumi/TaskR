import GuestLayout from '@/layouts/guest-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Banner } from '@/components/banner';
import { Head, router, usePage } from '@inertiajs/react';

type PageProps = {
    invitation: {
        workspaceName: string;
        inviterName: string;
        email: string;
        role: string;
        acceptUrl: string;
    };
};

/** Guest landing page for an invitation link: the acceptance itself happens
 * after login, which is forwarded the acceptance URL as return target. */
export default function AcceptInvitation() {
    const { invitation } = usePage<PageProps>().props;

    return (
        <GuestLayout>
            <Head title={`Join ${invitation.workspaceName}`} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">Join {invitation.workspaceName}</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">
                        {invitation.inviterName} invites you to join{' '}
                        <strong className="font-medium text-foreground">{invitation.workspaceName}</strong> as a{' '}
                        <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                            {invitation.role}
                        </Badge>
                        .
                    </p>

                    <Banner variant="info" className="mb-4 rounded-md text-[13px]">
                        As a <strong>member</strong> you get access to the projects, issues, comments and time
                        logging of the workspace. Managing statuses and members stays reserved for owners.
                    </Banner>

                    <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); }}>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-email">Invitation email</Label>
                            <Input
                                id="invitation-email"
                                type="email"
                                value={invitation.email}
                                readOnly
                                className="text-muted-foreground"
                            />
                        </div>
                        <Button
                            type="button"
                            size="lg"
                            className="w-full"
                            onClick={() => router.get(route('login'), { redirect: invitation.acceptUrl })}
                        >
                            Accept the invitation
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full"
                            onClick={() => router.get(route('login'))}
                        >
                            Decline
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
