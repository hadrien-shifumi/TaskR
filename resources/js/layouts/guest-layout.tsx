import type { ReactNode } from 'react';

export default function GuestLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-muted/40 p-4">
            <span className="text-2xl font-semibold">Taskr</span>
            {children}
        </div>
    );
}
