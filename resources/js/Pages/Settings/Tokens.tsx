import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';

interface TokenRow {
    id: number;
    name: string;
    createdAt: string;
}

export default function Tokens() {
    const { tokens } = usePage<{ tokens: TokenRow[] }>().props;
    const { flash } = usePage<{ flash?: { status?: string; newToken?: string } }>().props;

    const createForm = useForm({ name: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('tokens.store'), {
            onSuccess: () => createForm.reset(),
        });
    };

    const revoke = (token: TokenRow) => {
        if (!window.confirm(`Revoke token "${token.name}"? Agents using it will immediately lose access.`)) return;
        router.delete(route('tokens.destroy', token.id));
    };

    return (
        <AppLayout title="API tokens">
            <Head title="API tokens" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Personal access tokens let AI agents use Taskr through the MCP server at <code>/mcp</code>.
                </p>
            </div>

            <form onSubmit={submitCreate} className="mb-6 flex items-end gap-2">
                <div className="grid gap-2">
                    <Label htmlFor="token-name">New token name</Label>
                    <Input
                        id="token-name"
                        value={createForm.data.name}
                        onChange={(e) => createForm.setData('name', e.target.value)}
                        placeholder="Agent Claude"
                        required
                    />
                    {createForm.errors.name ? (
                        <p className="text-sm text-destructive">{createForm.errors.name}</p>
                    ) : null}
                </div>
                <Button type="submit" disabled={createForm.processing}>
                    Create token
                </Button>
            </form>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Created</TableHead>
                        <TableHead className="w-32 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {tokens.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={3} className="text-muted-foreground">
                                No API tokens yet.
                            </TableCell>
                        </TableRow>
                    ) : null}
                    {tokens.map((token) => (
                        <TableRow key={token.id}>
                            <TableCell className="font-medium">{token.name}</TableCell>
                            <TableCell>{new Date(token.createdAt).toLocaleDateString()}</TableCell>
                            <TableCell className="text-right">
                                <Button variant="ghost" size="sm" onClick={() => revoke(token)}>
                                    Revoke
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <Dialog open={Boolean(flash?.newToken)} onOpenChange={() => {}}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Token created</DialogTitle>
                        <DialogDescription>
                            Copy this token now. For security, it will never be shown again.
                        </DialogDescription>
                    </DialogHeader>
                    <pre className="overflow-x-auto rounded-md bg-muted/50 px-4 py-2 text-sm">
                        {flash?.newToken}
                    </pre>
                    <DialogFooter>
                        <Button onClick={() => router.get(route('settings.tokens'))}>Done</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
