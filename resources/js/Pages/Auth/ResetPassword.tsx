import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type PageProps = {
    token: string;
    email: string;
};

export default function ResetPassword() {
    const { t } = useTranslation();
    const { token, email } = usePage<PageProps>().props;

    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(route('password.update'));
    };

    return (
        <GuestLayout>
            <Head title={t('auth.reset.title')} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <h1 className="text-[19px] font-semibold tracking-tight">{t('auth.reset.title')}</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">{t('auth.reset.subtitle')}</p>

                    <form onSubmit={submit} className="grid gap-3" data-test="reset-password-form">
                        <input type="hidden" name="token" value={data.token} />
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">{t('auth.reset.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? true : undefined}
                                data-test="reset-email"
                            />
                            {errors.email ? <p className="text-sm text-destructive">{errors.email}</p> : null}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="password">{t('auth.reset.password')}</Label>
                            <Input
                                id="password"
                                type="password"
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={errors.password ? true : undefined}
                                data-test="reset-password"
                            />
                            <p className="text-sm text-muted-foreground" data-test="reset-password-hint">
                                {t('auth.reset.passwordHint')}
                            </p>
                            {errors.password ? <p className="text-sm text-destructive">{errors.password}</p> : null}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="password_confirmation">{t('auth.reset.confirmPassword')}</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                aria-invalid={errors.password_confirmation ? true : undefined}
                                data-test="reset-password-confirmation"
                            />
                            {errors.password_confirmation ? (
                                <p className="text-sm text-destructive">{errors.password_confirmation}</p>
                            ) : null}
                        </div>
                        <Button type="submit" size="lg" className="w-full" disabled={processing} data-test="reset-submit">
                            {t('auth.reset.submit')}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
