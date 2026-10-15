import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
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
            <Head title="Register" />
            <Card className="w-full max-w-sm">
                <CardContent className="px-6 py-6">
                    <div
                        role="tablist"
                        aria-label="Access mode"
                        className="mb-4.5 flex gap-0.5 rounded-lg border bg-secondary p-0.75"
                    >
                        <Link
                            role="tab"
                            aria-selected="false"
                            href={route('login')}
                            className="flex h-7.5 flex-1 items-center justify-center rounded-md text-[13px] font-medium text-muted-foreground hover:text-foreground"
                        >
                            Log in
                        </Link>                        <span
                            role="tab"
                            aria-selected="true"
                            className="flex h-7.5 flex-1 items-center justify-center rounded-md bg-card text-[13px] font-medium shadow-xs"
                        >
                            Sign up
                        </span>
                    </div>

                    <h1 className="text-[19px] font-semibold tracking-tight">Create your account</h1>
                    <p className="mt-1 mb-4.5 text-sm text-muted-foreground">
                        Your first workspace is created automatically.
                    </p>

                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Full name</Label>
                            <Input
                                id="name"
                                placeholder="Camille Riva"
                                required
                                autoFocus
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {fieldError('name')}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="camille@studionova.fr"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {fieldError('email')}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder="8 characters minimum"
                                required
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {fieldError('password')}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                            />
                            {fieldError('password_confirmation')}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="workspace_name">Workspace name (optional)</Label>
                            <Input
                                id="workspace_name"
                                placeholder="Studio Nova"
                                value={data.workspace_name}
                                onChange={(e) => setData('workspace_name', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Created with the 4 default statuses: Backlog, In Progress, Done, Cancelled.
                            </p>
                            {fieldError('workspace_name')}
                        </div>
                        <Button type="submit" size="lg" className="w-full" disabled={processing}>
                            Register
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
