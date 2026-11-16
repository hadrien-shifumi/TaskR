import { FilterSelect } from '@/components/filter-select';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import {
    BookOpen,
    ChevronDown,
    ChevronRight,
    Folder,
    FolderSearch,
    Globe,
    Plus,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

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
    /**
     * Active scope: a project id, 'transverse', or ''/undefined when no
     * scope is chosen yet — the tree then gives way to a pick-a-scope
     * invite.
     */
    projectFilter?: string;
    /** Scope change handler; omit on pages that offer no scope select. */
    onProjectFilterChange?: (value: string) => void;
    /** Create-dialog opener; omit to hide the empty-state CTA buttons. */
    onCreatePage?: () => void;
    /**
     * Create-dialog opener pre-filling a specific parent project — wired
     * to the per-section "+" buttons.
     */
    onCreatePageInProject?: (projectId: string | null) => void;
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

/** Parent ids of a page, root first — used to auto-expand the tree. */
function ancestorIds(pages: PageRef[], pageId: string): string[] {
    const byId = new Map(pages.map((page) => [page.id, page]));
    const ids: string[] = [];
    let current = byId.get(pageId);
    while (current?.parentId) {
        ids.push(current.parentId);
        current = byId.get(current.parentId);
    }
    return ids.reverse();
}

/**
 * Maquette nav-item pattern applied to a tree row. A node with children
 * gets a chevron toggling its branch; leaves get a spacer so titles stay
 * aligned across depths.
 */
function TreeRow({
    node,
    depth,
    selectedId,
    projectFilter,
    expanded,
    onToggle,
}: {
    node: TreeNode;
    depth: number;
    selectedId: string | null;
    projectFilter?: string;
    expanded: Set<string>;
    onToggle: (id: string) => void;
}) {
    const { t } = useTranslation();
    const hasChildren = node.children.length > 0;
    const isOpen = expanded.has(node.id);

    return (
        <>
            <div
                className="flex items-center gap-0.5"
                style={{ paddingLeft: `${0.75 + depth * 1}rem` }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => onToggle(node.id)}
                        aria-expanded={isOpen}
                        aria-label={isOpen ? t('knowledge.collapse') : t('knowledge.expand')}
                        data-test={`knowledge-toggle-${node.id}`}
                        className="flex size-4.5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        {isOpen ? (
                            <ChevronDown className="size-3.5" aria-hidden />
                        ) : (
                            <ChevronRight className="size-3.5" aria-hidden />
                        )}
                    </button>
                ) : (
                    <span className="size-4.5 shrink-0" aria-hidden />
                )}
                <Link
                    href={
                        projectFilter
                            ? route('knowledge.show', { page: node.id, project: projectFilter })
                            : route('knowledge.show', node.id)
                    }
                    aria-current={node.id === selectedId ? 'page' : undefined}
                    className="min-w-0 flex-1 truncate rounded-lg px-2 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground aria-[current=page]:bg-secondary aria-[current=page]:font-medium aria-[current=page]:text-foreground"
                >
                    {node.title}
                </Link>
            </div>
            {hasChildren && isOpen
                ? node.children.map((child) => (
                      <TreeRow
                          key={child.id}
                          node={child}
                          depth={depth + 1}
                          selectedId={selectedId}
                          projectFilter={projectFilter}
                          expanded={expanded}
                          onToggle={onToggle}
                      />
                  ))
                : null}
        </>
    );
}

type SidebarSectionProps = {
    label: string;
    icon: LucideIcon;
    trees: TreeNode[];
    selectedId: string | null;
    projectFilter?: string;
    expanded: Set<string>;
    onToggle: (id: string) => void;
    /** Per-section "+" opener; omit to hide the button. */
    onCreatePageInProject?: (projectId: string | null) => void;
    /** Project pre-filled by the section "+" (null = transverse). */
    sectionProjectId?: string | null;
};

/** Sidebar group: mono heading (icon, label, page count) over its tree. */
function SidebarSection({
    label,
    icon: Icon,
    trees,
    selectedId,
    projectFilter,
    expanded,
    onToggle,
    onCreatePageInProject,
    sectionProjectId,
}: SidebarSectionProps) {
    const { t } = useTranslation();

    if (trees.length === 0) {
        return null;
    }

    return (
        <section>
            <h3 className="flex items-center gap-1.5 px-2 pb-1 font-mono text-[12px] tracking-[0.08em] text-muted-foreground uppercase">
                <Icon className="size-3.5 shrink-0" aria-hidden />
                <span className="min-w-0 truncate">{label}</span>
                <span className="ml-auto shrink-0 rounded-full bg-secondary px-1.5 py-0.5 font-sans text-[10px] tabular-nums text-muted-foreground">
                    {countNodes(trees)}
                </span>
                {onCreatePageInProject ? (
                    <button
                        type="button"
                        onClick={() => onCreatePageInProject(sectionProjectId ?? null)}
                        aria-label={t('knowledge.newPageInProject')}
                        title={t('knowledge.newPageInProject')}
                        data-test={`knowledge-section-add-${sectionProjectId ?? 'transverse'}`}
                        className="flex size-4.5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        <Plus className="size-3.5" aria-hidden />
                    </button>
                ) : null}
            </h3>
            {trees.map((node) => (
                <TreeRow
                    key={node.id}
                    node={node}
                    depth={0}
                    selectedId={selectedId}
                    projectFilter={projectFilter}
                    expanded={expanded}
                    onToggle={onToggle}
                />
            ))}
        </section>
    );
}

/**
 * Knowledge sidebar: a sticky card with a page count, an optional scope
 * select (a project or the transverse scope — one at a time, no "all"
 * entry) and the matching section. With no scope chosen the tree gives
 * way to a pick-a-scope invite. The tree scrolls inside the card on
 * desktop; centered empty states cover the no-pages and
 * scope-matches-nothing cases. Branches fold/unfold through their
 * chevron; opening a page auto-expands its ancestor path.
 */
export function KnowledgeSidebar({
    pages,
    projects,
    selectedId,
    projectFilter,
    onProjectFilterChange,
    onCreatePage,
    onCreatePageInProject,
}: KnowledgeSidebarProps) {
    const { t } = useTranslation();

    const [expanded, setExpanded] = useState<Set<string>>(() => new Set());

    const trees = buildTree(pages);
    const crossWorkspaceTrees = trees.filter((node) => !node.projectId);

    // Opening a page auto-expands its ancestor path so the tree shows
    // where the page lives; branches folded by hand stay folded.
    useEffect(() => {
        if (selectedId === null) {
            return;
        }
        const path = ancestorIds(pages, selectedId);
        if (path.length === 0) {
            return;
        }
        setExpanded((previous) => {
            const next = new Set(previous);
            let changed = false;
            path.forEach((id) => {
                if (!next.has(id)) {
                    next.add(id);
                    changed = true;
                }
            });
            return changed ? next : previous;
        });
    }, [selectedId, pages]);

    const toggle = (id: string) => {
        setExpanded((previous) => {
            const next = new Set(previous);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    // No scope chosen yet: the tree waits for a pick. With an active
    // scope the server already narrowed `pages`; when the scope matches
    // nothing, offer a scoped empty state naming it.
    const scopeActive = projectFilter !== undefined && projectFilter !== '';
    const sectionVisible =
        projectFilter === 'transverse'
            ? crossWorkspaceTrees.length > 0
            : trees.some((node) => node.projectId === projectFilter);
    const filteredProjectName =
        projectFilter === 'transverse'
            ? t('knowledge.crossWorkspace')
            : (projects.find((project) => project.id === projectFilter)?.name ?? projectFilter ?? '');

    const createCta = onCreatePage ? (
        <Button size="sm" className="mt-2" onClick={onCreatePage}>
            <Plus aria-hidden />
            {t('knowledge.newPage')}
        </Button>
    ) : null;

    return (
        <aside className="overflow-hidden rounded-xl border bg-card lg:sticky lg:top-[calc(80px+env(safe-area-inset-top))] lg:self-start">
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
                        placeholder={t('knowledge.filters.placeholder')}
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
                {!scopeActive ? (
                    <div className="flex flex-col items-center gap-1.5 px-2 py-6 text-center">
                        <FolderSearch className="size-8 text-muted-foreground/50" aria-hidden />
                        <p className="text-sm font-medium">{t('knowledge.noScope')}</p>
                        <p className="text-sm text-muted-foreground">{t('knowledge.noScopeHint')}</p>
                        {createCta}
                    </div>
                ) : (
                    <>
                        <SidebarSection
                            label={t('knowledge.crossWorkspace')}
                            icon={Globe}
                            trees={crossWorkspaceTrees}
                            selectedId={selectedId}
                            projectFilter={projectFilter}
                            expanded={expanded}
                            onToggle={toggle}
                            onCreatePageInProject={onCreatePageInProject}
                            sectionProjectId={null}
                        />
                        {projects.map((project) => (
                            <SidebarSection
                                key={project.id}
                                label={project.name}
                                icon={Folder}
                                trees={trees.filter((node) => node.projectId === project.id)}
                                selectedId={selectedId}
                                projectFilter={projectFilter}
                                expanded={expanded}
                                onToggle={toggle}
                                onCreatePageInProject={onCreatePageInProject}
                                sectionProjectId={project.id}
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
                        ) : (
                            !sectionVisible && (
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
                            )
                        )}
                    </>
                )}
            </nav>
        </aside>
    );
}
