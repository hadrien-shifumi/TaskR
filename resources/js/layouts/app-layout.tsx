import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Head, router, usePage } from '@inertiajs/react';
import type { PageSharedProps } from '@/types';

interface AppLayoutProps {
    title?: string;
    children: ReactNode;
}

const triggerButtonClass =
    'inline-flex h-8 items-center justify-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground';

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth, workspace, workspaces } = usePage<PageSharedProps>().props;

    return (
        <div className="min-h-screen bg-background">
            <header className="border-b">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4">
                    <span className="text-lg font-semibold">Taskr</span>
                    <nav className="flex items-center gap-1 text-sm">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get(route('projects.index'))}
                        >
                            Projects
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get(route('issues.index'))}
                        >
                            Issues
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get(route('settings.statuses'))}
                        >
                            Statuses
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get(route('settings.templates'))}
                        >
                            Templates
                        </Button>
                    </nav>
                    <div className="ml-auto flex items-center gap-2">
                        <DropdownMenu>
                            <DropdownMenuTrigger className={triggerButtonClass}>
                                {workspace ? workspace.name : 'No workspace'}
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuGroup>
                                    <DropdownMenuLabel>Workspaces</DropdownMenuLabel>
                                    {workspaces.map((ws) => (
                                        <DropdownMenuItem
                                            key={ws.id}
                                            onClick={() => router.post(route('workspaces.switch', ws.id))}
                                        >
                                            {ws.name}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {auth.user ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger className={triggerButtonClass}>{auth.user.name}</DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={() => router.post(route('logout'))}>
                                        Log out
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-8">
                {title ? <Head title={title} /> : null}
                {title ? <h1 className="mb-6 text-2xl font-semibold">{title}</h1> : null}
                {children}
            </main>
        </div>
    );
}
