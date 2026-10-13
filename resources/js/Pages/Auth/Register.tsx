import GuestLayout from '@/layouts/guest-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
        post(route('register'));
    };

    const fieldError = (field: keyof typeof data) =>
        errors[field] ? <p className="text-sm text-destructive">{errors[field]}</p> : null;

    return (
        <GuestLayout>
            <Head title="Register" />
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <CardTitle>Create your account</CardTitle>
                    <CardDescription>A workspace with default statuses is created for you.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" required autoFocus value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {fieldError('name')}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            {fieldError('email')}
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
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="workspace_name">Workspace name (optional)</Label>
                            <Input
                                id="workspace_name"
                                placeholder="My Studio"
                                value={data.workspace_name}
                                onChange={(e) => setData('workspace_name', e.target.value)}
                            />
                            {fieldError('workspace_name')}
                        </div>
                        <Button type="submit" disabled={processing}>
                            Register
                        </Button>
                    </form>
                    <p className="mt-4 text-center text-sm text-muted-foreground">
                        Already registered?{' '}
                        <Link href={route('login')} className="underline">
                            Log in
                        </Link>
                    </p>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
