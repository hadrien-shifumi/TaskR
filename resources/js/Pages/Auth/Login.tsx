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
            <Card className="w-full py-6">
                <CardContent className="px-6">
                    <div
                        role="tablist"
                        aria-label="Access mode"
                        className="mb-4.5 flex gap-0.5 rounded-md border bg-secondary p-0.75"
                    >
                        <span
                            role="tab"
                            aria-selected="true"
                            className="flex h-7.5 flex-1 items-center justify-center rounded-[6px] bg-card text-[13px] font-medium shadow-xs"
                        >
                            Log in
                        </span>
                        <Link
                            role="tab"
                            aria-selected="false"
                            href={route('register')}
                            className="flex h-7.5 flex-1 items-center justify-center rounded-[6px] text-[13px] font-medium text-muted-foreground hover:text-foreground"
                        >
                            Sign up
                        </Link>
                    </div>

                    <h1 className="text-[19px] font-semibold tracking-tight">Welcome back</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">Access your workspaces.</p>

                    <form onSubmit={submit} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="camille@studionova.fr"
                                required
                                autoFocus
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? true : undefined}
                            />
                            {errors.email ? <p className="text-sm text-destructive">{errors.email}</p> : null}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder="••••••••"
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={errors.password ? true : undefined}
                            />
                            {errors.password ? <p className="text-sm text-destructive">{errors.password}</p> : null}
                        </div>
                        {/* The maquette sits the submit button 4px under the last field. */}
                        <Button type="submit" size="lg" className="-mt-2 w-full" disabled={processing}>
                            Log in
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
