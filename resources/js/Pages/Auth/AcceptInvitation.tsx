import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Info } from 'lucide-react';
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
            <Card className="w-full max-w-sm">
                <CardContent className="px-6 py-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">Join {invitation.workspaceName}</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">
                        {invitation.inviterName} invites you to join{' '}
                        <strong className="font-medium text-foreground">{invitation.workspaceName}</strong> as a{' '}
                        <span className="inline-flex items-center rounded-full bg-secondary px-2 py-0.5 font-mono text-xs text-muted-foreground">
                            {invitation.role}
                        </span>
                        .
                    </p>

                    <p className="mb-4.5 flex items-start gap-2.5 rounded-lg border border-info/30 bg-info/10 px-3.5 py-3 text-[13px]">
                        <Info className="mt-0.5 size-4 shrink-0 text-info" aria-hidden />
                        <span>
                            As a <strong>member</strong> you get access to the projects, issues, comments and time
                            logging of the workspace. Managing statuses and members stays reserved for owners.
                        </span>
                    </p>

                    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); }}>
                        <div className="grid gap-2">
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
