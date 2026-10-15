import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function Login() {
    // Guest pages (invitations…) hand their return target over as a query
    // parameter; the login POST forwards it so the responder can send the
    // user back where they came from (same-app paths only, guarded server-side).
    const redirect = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('redirect') ?? '';

    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        redirect,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(route('login.attempt'));
    };

    return (
        <GuestLayout>
            <Head title="Log in" />
            <Card className="w-full max-w-sm">
                <CardContent className="px-6 py-6">
                    <div
                        role="tablist"
                        aria-label="Access mode"
                        className="mb-4.5 flex gap-0.5 rounded-lg border bg-secondary p-0.75"
                    >
                        <span
                            role="tab"
                            aria-selected="true"
                            className="flex h-7.5 flex-1 items-center justify-center rounded-md bg-card text-[13px] font-medium shadow-xs"
                        >
                            Log in
                        </span>
                        <Link
                            role="tab"
                            aria-selected="false"
                            href={route('register')}
                            className="flex h-7.5 flex-1 items-center justify-center rounded-md text-[13px] font-medium text-muted-foreground hover:text-foreground"
                        >
                            Sign up
                        </Link>
                    </div>

                    <h1 className="text-[19px] font-semibold tracking-tight">Welcome back</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">Access your workspaces.</p>

                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="camille@studionova.fr"
                                required
                                autoFocus
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {errors.email ? <p className="text-sm text-destructive">{errors.email}</p> : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder="••••••••"
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password ? <p className="text-sm text-destructive">{errors.password}</p> : null}
                        </div>
                        <Button type="submit" size="lg" className="w-full" disabled={processing}>
                            Log in
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
