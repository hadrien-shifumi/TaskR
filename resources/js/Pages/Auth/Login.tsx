import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(route('login.attempt'));
    };

    return (
        <GuestLayout>
            <Head title="Log in" />
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <CardTitle>Log in</CardTitle>
                    <CardDescription>Access your workspaces.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
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
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password ? <p className="text-sm text-destructive">{errors.password}</p> : null}
                        </div>
                        <Button type="submit" disabled={processing}>
                            Log in
                        </Button>
                    </form>
                    <p className="mt-4 text-center text-sm text-muted-foreground">
                        No account yet?{' '}
                        <Link href={route('register')} className="underline">
                            Register
                        </Link>
                    </p>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
