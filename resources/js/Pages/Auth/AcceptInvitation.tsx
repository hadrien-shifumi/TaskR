import GuestLayout from '@/layouts/guest-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Banner } from '@/components/banner';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type PageProps = {
    invitation: {
        workspaceName: string;
        inviterName: string;
        email: string;
        role: string;
        projects: string[];
        acceptUrl: string;
    };
};

/** Guest landing page for an invitation link: the acceptance itself happens
 * after login, which is forwarded the acceptance URL as return target. */
export default function AcceptInvitation() {
    const { invitation } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const isGuest = invitation.role === 'guest';

    return (
        <GuestLayout>
            <Head title={t('auth.invitation.joinTitle', { workspace: invitation.workspaceName })} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">
                        {t('auth.invitation.joinTitle', { workspace: invitation.workspaceName })}
                    </h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">
                        {t('auth.invitation.invitedBy', { inviter: invitation.inviterName })}{' '}
                        <strong className="font-medium text-foreground">{invitation.workspaceName}</strong>{' '}
                        {t('auth.invitation.asRole')}{' '}
                        <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                            {t(`layout.roles.${invitation.role}`)}
                        </Badge>
                        .
                    </p>

                    <Banner variant="info" className="mb-4 rounded-md text-[13px]">
                        {isGuest ? (
                            <>
                                {t('auth.invitation.guestPrefix')}{' '}
                                <strong>{t('auth.invitation.guestRole')}</strong>
                                {t('auth.invitation.guestSuffix')}
                            </>
                        ) : (
                            <>
                                {t('auth.invitation.memberPrefix')}{' '}
                                <strong>{t('auth.invitation.memberRole')}</strong>
                                {t('auth.invitation.memberSuffix')}
                            </>
                        )}
                    </Banner>

                    {isGuest && invitation.projects.length > 0 ? (
                        <div className="grid gap-1.5 mb-4">
                            <Label htmlFor="invitation-projects">
                                {t('auth.invitation.scopeLabel')}
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                {invitation.projects.map((project) => (
                                    <Badge
                                        key={project}
                                        variant="secondary"
                                        className="rounded-full bg-secondary text-muted-foreground"
                                    >
                                        {project}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); }}>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-email">{t('auth.invitation.invitationEmail')}</Label>
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
                            {t('auth.invitation.accept')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full"
                            onClick={() => router.get(route('login'))}
                        >
                            {t('auth.invitation.decline')}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
