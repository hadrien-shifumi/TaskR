import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Register() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        workspace_name: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(route('register.store'));
    };

    const fieldError = (field: keyof typeof data) =>
        errors[field] ? <p className="text-sm text-destructive">{errors[field]}</p> : null;

    return (
        <GuestLayout>
            <Head title={t('auth.register.title')} />
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <div
                        role="tablist"
                        aria-label={t('auth.accessMode')}
                        className="mb-4.5 flex gap-0.5 rounded-md border bg-secondary p-0.75"
                    >
                        <Link
                            role="tab"
                            aria-selected="false"
                            href={route('login')}
                            className="flex h-7.5 flex-1 items-center justify-center rounded-[6px] text-[13px] font-medium text-muted-foreground hover:text-foreground"
                        >
                            {t('auth.loginTab')}
                        </Link>
                        <span
                            role="tab"
                            aria-selected="true"
                            className="flex h-7.5 flex-1 items-center justify-center rounded-[6px] bg-card text-[13px] font-medium shadow-xs"
                        >
                            {t('auth.signupTab')}
                        </span>
                    </div>

                    <h1 className="text-[19px] font-semibold tracking-tight">{t('auth.register.createAccount')}</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">
                        {t('auth.register.subtitle')}
                    </p>

                    <form onSubmit={submit} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">{t('auth.register.fullName')}</Label>
                            <Input
                                id="name"
                                placeholder="Camille Riva"
                                required
                                autoFocus
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                aria-invalid={errors.name ? true : undefined}
                            />
                            {fieldError('name')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">{t('auth.register.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="camille@studionova.fr"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? true : undefined}
                            />
                            {fieldError('email')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="password">{t('auth.register.password')}</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder={t('auth.register.passwordHint')}
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={errors.password ? true : undefined}
                            />
                            {fieldError('password')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="password_confirmation">{t('auth.register.confirmPassword')}</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                aria-invalid={errors.password_confirmation ? true : undefined}
                            />
                            {fieldError('password_confirmation')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="workspace_name">{t('auth.register.workspaceName')}</Label>
                            <Input
                                id="workspace_name"
                                placeholder="Studio Nova"
                                value={data.workspace_name}
                                onChange={(e) => setData('workspace_name', e.target.value)}
                                aria-invalid={errors.workspace_name ? true : undefined}
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('auth.register.workspaceHint')}
                            </p>
                            {fieldError('workspace_name')}
                        </div>
                        {/* The maquette sits the submit button 4px under the last field. */}
                        <Button type="submit" size="lg" className="-mt-2 w-full" disabled={processing}>
                            {t('auth.register.submit')}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
