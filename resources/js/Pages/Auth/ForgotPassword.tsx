import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Banner } from '@/components/banner';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function ForgotPassword() {
    const { t } = useTranslation();
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title={t('auth.forgot.title')} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">{t('auth.forgot.title')}</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">{t('auth.forgot.subtitle')}</p>

                    {flash?.status ? (
                        <Banner variant="info" role="status" className="mb-4 rounded-md text-[13px]">
                            {flash.status}
                        </Banner>
                    ) : null}

                    <form onSubmit={submit} className="grid gap-3" data-test="forgot-password-form">
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">{t('auth.forgot.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="camille@studionova.fr"
                                required
                                autoFocus
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? true : undefined}
                                data-test="forgot-email"
                            />
                            {errors.email ? <p className="text-sm text-destructive">{errors.email}</p> : null}
                        </div>
                        <Button type="submit" size="lg" className="w-full" disabled={processing} data-test="forgot-submit">
                            {t('auth.forgot.submit')}
                        </Button>
                    </form>

                    <p className="mt-4 text-center text-sm text-muted-foreground">
                        <Link href={route('login')} className="font-medium text-foreground hover:underline">
                            {t('auth.forgot.backToLogin')}
                        </Link>
                    </p>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
