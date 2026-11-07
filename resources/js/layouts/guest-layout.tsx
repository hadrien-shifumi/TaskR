import type { ReactNode } from 'react';

export default function GuestLayout({ children }: { children: ReactNode }) {
    return (
        <div className="grid min-h-screen place-items-center bg-[radial-gradient(1100px_500px_at_15%_-10%,--theme(--color-primary/0.1),transparent_60%)] bg-background pl-[max(1.25rem,env(safe-area-inset-left))] pr-[max(1.25rem,env(safe-area-inset-right))] pt-[max(2.5rem,env(safe-area-inset-top))] pb-[max(2.5rem,env(safe-area-inset-bottom))]">
            <div className="w-full max-w-[400px]">
                <div className="mb-5.5 flex items-center justify-center">
                    <img
                        src="/assets/taskr-lockup.svg"
                        alt="Taskr"
                        className="h-8 w-auto"
                    />
                </div>
                {children}
            </div>
        </div>
    );
}
