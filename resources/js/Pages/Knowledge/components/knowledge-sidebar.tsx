import { FilterSelect } from '@/components/filter-select';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { BookOpen, Folder, Globe, Plus, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export interface PageRef {
    id: string;
    title: string;
    parentId: string | null;
    projectId: string | null;
    updatedAt: string | null;
}

export interface ProjectOption {
    id: string;
    name: string;
}

type TreeNode = PageRef & { children: TreeNode[] };

type KnowledgeSidebarProps = {
    pages: PageRef[];
    projects: ProjectOption[];
    /** Page currently open — highlighted as aria-current in the tree. */
    selectedId: string | null;
    /** Active sidebar project filter ('' = all projects). */
    projectFilter?: string;
    /** Filter handler; omit on pages that don't offer the project filter. */
    onProjectFilterChange?: (value: string) => void;
    /** Create-dialog opener; omit to hide the empty-state CTA buttons. */
    onCreatePage?: () => void;
};

function buildTree(pages: PageRef[]): TreeNode[] {
    const byId = new Map<string, TreeNode>();
    pages.forEach((page) => byId.set(page.id, { ...page, children: [] }));

    const roots: TreeNode[] = [];
    byId.forEach((node) => {
        if (node.parentId && byId.has(node.parentId)) {
            byId.get(node.parentId)!.children.push(node);
        } else {
            roots.push(node);
        }
    });

    return roots;
}

/** Recursive page count — roots plus every nested child. */
function countNodes(nodes: TreeNode[]): number {
    return nodes.reduce((total, node) => total + 1 + countNodes(node.children), 0);
}

/** Maquette sidebar nav-item pattern applied to a tree row. */
function TreeRow({ node, depth, selectedId }: { node: TreeNode; depth: number; selectedId: string | null }) {
    return (
        <>
            <Link
                href={route('knowledge.show', node.id)}
                aria-current={node.id === selectedId ? 'page' : undefined}
                className="block truncate rounded-lg px-2.5 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground aria-[current=page]:bg-secondary aria-[current=page]:font-medium aria-[current=page]:text-foreground"
                style={{ paddingLeft: `${0.75 + depth * 1}rem` }}
            >
                {node.title}
            </Link>
            {node.children.map((child) => (
                <TreeRow key={child.id} node={child} depth={depth + 1} selectedId={selectedId} />
            ))}
        </>
    );
}

type SidebarSectionProps = {
    label: string;
    icon: LucideIcon;
    trees: TreeNode[];
    selectedId: string | null;
};

/** Sidebar group: mono heading (icon, truncated label, page count) over its tree. */
function SidebarSection({ label, icon: Icon, trees, selectedId }: SidebarSectionProps) {
    if (trees.length === 0) return null;

    return (
        <section>
            <h3 className="flex items-center gap-1.5 px-2 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                <Icon className="size-3.5 shrink-0" aria-hidden />
                <span className="min-w-0 truncate">{label}</span>
                <span className="ml-auto shrink-0 rounded-full bg-secondary px-1.5 py-0.5 font-sans text-[10px] tabular-nums text-muted-foreground">
                    {countNodes(trees)}
                </span>
            </h3>
            {trees.map((node) => (
                <TreeRow key={node.id} node={node} depth={0} selectedId={selectedId} />
            ))}
        </section>
    );
}

/**
 * Knowledge page tree: sticky card with a global page count, an optional
 * project filter select, and one section per project (plus the transverse
 * one) — each headed by an icon and its recursive page count. The tree
 * scrolls inside the card on desktop. Centered empty states cover the
 * no-pages-at-all case and the active-project-filter-matches-nothing case.
 */
export function KnowledgeSidebar({
    pages,
    projects,
    selectedId,
    projectFilter,
    onProjectFilterChange,
    onCreatePage,
}: KnowledgeSidebarProps) {
    const { t } = useTranslation();

    const trees = buildTree(pages);
    const crossWorkspaceTrees = trees.filter((node) => !node.projectId);

    // A project filter hides every non-matching section; when none survives,
    // offer a scoped empty state naming the filtered project (or transverse).
    const filterActive = projectFilter !== undefined && projectFilter !== '';
    const sectionVisible =
        !filterActive ||
        (projectFilter === 'transverse'
            ? crossWorkspaceTrees.length > 0
            : trees.some((node) => node.projectId === projectFilter));
    const filteredProjectName =
        projectFilter === 'transverse'
            ? t('knowledge.crossWorkspace')
            : (projects.find((project) => project.id === projectFilter)?.name ??
                projectFilter ??
                '');

    const createCta = onCreatePage ? (
        <Button size="sm" className="mt-2" onClick={onCreatePage}>
            <Plus aria-hidden />
            {t('knowledge.newPage')}
        </Button>
    ) : null;

    return (
        <aside className="overflow-hidden rounded-xl border bg-card lg:sticky lg:top-20 lg:self-start">
            <div className="flex items-center gap-2.5 border-b px-4 py-3">
                <h2 className="text-sm font-semibold">{t('knowledge.pages')}</h2>
                <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                    {pages.length}
                </span>
            </div>
            {onProjectFilterChange ? (
                <div className="border-b px-3 py-2.5">
                    <FilterSelect
                        testId="filter-select-project"
                        value={projectFilter ?? ''}
                        options={[
                            { value: 'transverse', label: t('knowledge.filters.transverseOnly') },
                            ...projects.map((project) => ({ value: project.id, label: project.name })),
                        ]}
                        allLabel={t('knowledge.filters.allProjects')}
                        ariaLabel={t('knowledge.filters.projectAria')}
                        onChange={onProjectFilterChange}
                        className="w-full"
                    />
                </div>
            ) : null}
            <nav
                aria-label={t('knowledge.treeAria')}
                className="flex flex-col gap-3 overflow-y-auto px-3 py-3 lg:max-h-[calc(100dvh-13rem)]"
            >
                <SidebarSection
                    label={t('knowledge.crossWorkspace')}
                    icon={Globe}
                    trees={crossWorkspaceTrees}
                    selectedId={selectedId}
                />
                {projects.map((project) => (
                    <SidebarSection
                        key={project.id}
                        label={project.name}
                        icon={Folder}
                        trees={trees.filter((node) => node.projectId === project.id)}
                        selectedId={selectedId}
                    />
                ))}
                {pages.length === 0 ? (
                    <div className="flex flex-col items-center gap-1.5 px-2 py-6 text-center">
                        <BookOpen className="size-8 text-muted-foreground/50" aria-hidden />
                        <p className="text-sm font-medium">{t('knowledge.noPages')}</p>
                        <p className="text-sm text-muted-foreground">
                            {t('knowledge.noPagesHint')}
                        </p>
                        {createCta}
                    </div>
                ) : filterActive && !sectionVisible ? (
                    <div className="flex flex-col items-center gap-1.5 px-2 py-6 text-center">
                        <Folder className="size-8 text-muted-foreground/50" aria-hidden />
                        <p className="text-sm font-medium">
                            {t('knowledge.noPagesInProject', { project: filteredProjectName })}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t('knowledge.noPagesInProjectHint')}
                        </p>
                        {createCta}
                    </div>
                ) : null}
            </nav>
        </aside>
    );
}
