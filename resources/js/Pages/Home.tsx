import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface HomeProps {
    appName: string;
}

export default function Home({ appName }: HomeProps) {
    return (
        <AppLayout>
            <Head title="Home" />
            <div className="flex flex-col items-center gap-2 py-24 text-center">
                <h1 className="text-3xl font-semibold">{appName}</h1>
                <p className="text-muted-foreground">Foundation placeholder page.</p>
            </div>
        </AppLayout>
    );
}
