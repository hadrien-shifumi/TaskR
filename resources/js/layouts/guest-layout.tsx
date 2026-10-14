import type { ReactNode } from 'react';

export default function GuestLayout({ children }: { children: ReactNode }) {
    return (
        <div className="grid min-h-screen place-items-center bg-[radial-gradient(1100px_500px_at_15%_-10%,--theme(--color-primary/0.1),transparent_60%)] bg-background p-10">
            <div className="w-full max-w-sm">
                <div className="mb-5.5 flex items-center justify-center gap-2.5">
                    <span className="grid size-8 place-items-center rounded-[9px] bg-primary text-base font-bold text-primary-foreground">
                        T
                    </span>
                    <span className="text-[19px] font-semibold tracking-tight">Taskr</span>
                </div>
                {children}
            </div>
        </div>
    );
}
