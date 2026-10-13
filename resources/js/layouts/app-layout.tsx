import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

interface AppLayoutProps {
    title?: string;
    children: ReactNode;
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    return (
        <div className="min-h-screen bg-background">
            <header className="border-b">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4">
                    <span className="text-lg font-semibold">Taskr</span>
                    <nav className="flex items-center gap-1 text-sm">
                        {/* Placeholder nav — sections wired in later tasks */}
                        <Button variant="ghost" size="sm" disabled>
                            Projects
                        </Button>
                        <Button variant="ghost" size="sm" disabled>
                            Issues
                        </Button>
                    </nav>
                    <div className="ml-auto">
                        {/* Workspace selector placeholder — implemented with workspaces (task 2.5) */}
                        <Button variant="outline" size="sm" disabled>
                            Workspace
                        </Button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-8">
                {title ? <h1 className="mb-6 text-2xl font-semibold">{title}</h1> : null}
                {children}
            </main>
        </div>
    );
}
