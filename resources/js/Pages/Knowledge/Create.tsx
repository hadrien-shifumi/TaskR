import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { KnowledgeParentPicker } from '@/components/knowledge-parent-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { lazy, Suspense } from 'react';
import type { PageSharedProps } from '@/types';
import { useTranslation } from 'react-i18next';

// TipTap (the markdown editor's engine) is a heavy dependency: it rides a
// lazy chunk loaded on first editor open, not with the page bundle.
const MarkdownEditor = lazy(() =>
    import('@/components/ui/markdown-editor').then((m) => ({ default: m.MarkdownEditor })),
);

interface ProjectOption {
    id: string;
    name: string;
}

type PageProps = {
    projects: ProjectOption[];
    /** Prefill hints riding the query string (project scope, parent, title). */
    prefillProject?: string | null;
    prefillParent?: string | null;
    prefillParentTitle?: string | null;
    prefillTitle?: string | null;
};

const NO_PROJECT = '__none__';

export default function KnowledgeCreate() {
    const { t } = useTranslation();
    const { projects, prefillProject, prefillParent, prefillParentTitle, prefillTitle } =
        usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;

    const form = useForm({
        title: prefillTitle ?? '',
        body: '',
        project_id: prefillProject ?? '',
        parent_id: prefillParent ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('knowledge.store'), {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AppLayout
            title={t('knowledge.title')}
            subtitle={workspace?.name}
            actions={
                <Link
                    href={route('knowledge.index')}
                    className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    {t('knowledge.backToList')}
                </Link>
            }
        >
            <Head title={t('knowledge.newPage')} />

            <form
                onSubmit={submit}
                className="mx-auto flex max-w-3xl flex-col gap-5 rounded-xl border bg-card p-5"
                data-test="knowledge-create-form"
            >
                <div className="grid gap-2">
                    <Label htmlFor="create-knowledge-title">{t('issues.fields.title')}</Label>
                    <Input
                        id="create-knowledge-title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        required
                        autoFocus
                        autoComplete="off"
                    />
                    {form.errors.title ? (
                        <p className="text-sm text-destructive">{form.errors.title}</p>
                    ) : null}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="create-knowledge-body">{t('knowledge.body')}</Label>
                    <Suspense
                        fallback={
                            <div
                                className="min-h-48 animate-pulse rounded-lg border bg-muted/40"
                                aria-hidden="true"
                            />
                        }
                    >
                        <MarkdownEditor
                            value={form.data.body}
                            onChange={(markdown) => form.setData('body', markdown)}
                        />
                    </Suspense>
                    {form.errors.body ? (
                        <p className="text-sm text-destructive">{form.errors.body}</p>
                    ) : null}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="create-knowledge-project">
                            {t('issues.fields.project')}
                        </Label>
                        <Select
                            value={form.data.project_id || NO_PROJECT}
                            onValueChange={(v) =>
                                form.setData(
                                    'project_id',
                                    v === NO_PROJECT ? '' : (v ?? ''),
                                )
                            }
                        >
                            <SelectTrigger
                                id="create-knowledge-project"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_PROJECT}>
                                    {t('knowledge.crossWorkspace')}
                                </SelectItem>
                                {projects.map((project) => (
                                    <SelectItem key={project.id} value={project.id}>
                                        {project.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.project_id ? (
                            <p className="text-sm text-destructive">{form.errors.project_id}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <KnowledgeParentPicker
                            value={form.data.parent_id}
                            onChange={(id) => form.setData('parent_id', id)}
                            currentTitle={prefillParentTitle}
                            inputId="create-knowledge-parent"
                        />
                        {form.errors.parent_id ? (
                            <p className="text-sm text-destructive">{form.errors.parent_id}</p>
                        ) : null}
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => router.get(route('knowledge.index'))}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('common.create')}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
