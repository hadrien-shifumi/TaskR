import GuestLayout from '@/layouts/guest-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type PageProps = {
    invitation: {
        email: string;
    };
};

/** Shown when a signed-in account opens an invitation addressed to another
 * email: nothing was joined, the workspace stays closed. */
export default function InvitationEmailMismatch() {
    const { invitation } = usePage<PageProps>().props;
    const { t } = useTranslation();

    return (
        <GuestLayout>
            <Head title={t('auth.invitationMismatch.title')} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">
                        {t('auth.invitationMismatch.title')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('auth.invitationMismatch.message', { email: invitation.email })}
                    </p>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
