import { Fragment, useState, type FormEvent, type ReactNode } from "react";
import { FormDialog } from "@/components/form-dialog";
import { initials } from "@/components/issue-badges";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Link, router, useForm, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import {
    AtSign,
    BookOpen,
    ChevronDown,
    Folder,
    Home,
    ListChecks,
    LogOut,
    Menu,
    Plus,
    SlidersHorizontal,
    Trash2,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import type { PageSharedProps } from "@/types";
import { LanguageSwitcher } from "@/components/language-switcher";

interface AppLayoutProps {
    /** Pagebar title — a string, or a node when badges sit next to it. */
    title?: ReactNode;
    /** Muted sentence rendered next to the pagebar title. */
    subtitle?: string;
    /** Content rendered before the title (back buttons, view switchers). */
    leading?: ReactNode;
    /** Page-level controls rendered at the right end of the pagebar. */
    actions?: ReactNode;
    /** Constrains the page body to the narrow reading width (settings). */
    narrow?: boolean;
    children: ReactNode;
}

type NavItem = {
    label: string;
    route: string;
    prefix: string;
    icon: LucideIcon;
    /** Shared prop rendered as a count pill when positive. */
    badge?: "unreadMentions";
};

type NavSection = {
    /** i18n key of the section label; omitted for ungrouped items. */
    label?: string;
    items: NavItem[];
};

/** Nav entries grouped by family; unlabelled sections stay flat. */
const NAV_SECTIONS: NavSection[] = [
    {
        items: [
            { label: "nav.home", route: "home", prefix: "/", icon: Home },
            {
                label: "nav.mentions",
                route: "mentions.index",
                prefix: "/mentions",
                icon: AtSign,
                badge: "unreadMentions",
            },
        ],
    },
    {
        label: "nav.sections.projects",
        items: [
            {
                label: "nav.projects",
                route: "projects.index",
                prefix: "/projects",
                icon: Folder,
            },
            {
                label: "nav.issues",
                route: "issues.index",
                prefix: "/issues",
                icon: ListChecks,
            },
        ],
    },
    {
        label: "nav.sections.knowledge",
        items: [
            {
                label: "nav.knowledge",
                route: "knowledge.index",
                prefix: "/knowledge",
                icon: BookOpen,
            },
            {
                label: "knowledge.trash.nav",
                route: "knowledge.trash",
                prefix: "/knowledge/trash",
                icon: Trash2,
            },
        ],
    },
    {
        items: [
            {
                label: "nav.settings",
                route: "settings.statuses",
                prefix: "/settings",
                icon: SlidersHorizontal,
            },
        ],
    },
];

/** Workspace switcher dropdown + creation dialog, shared by the desktop
 *  sidebar and the mobile navigation drawer. */
function WorkspaceSwitcher() {
    const { workspace, workspaces } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const [creatingWorkspace, setCreatingWorkspace] = useState(false);
    const workspaceForm = useForm({ name: "" });

    const submitWorkspace = (event: FormEvent) => {
        event.preventDefault();
        workspaceForm.post(route("workspaces.store"), {
            onSuccess: () => {
                setCreatingWorkspace(false);
                workspaceForm.reset();
            },
        });
    };

    if (!workspace) {
        return null;
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    aria-label={t("layout.switchWorkspace")}
                    className="flex w-full items-center gap-2 rounded-lg border bg-card px-2.5 py-2 text-left shadow-xs transition-colors hover:bg-accent"
                >
                    <span className="grid size-5.5 shrink-0 place-items-center rounded-md bg-primary/25 text-[11px] font-bold text-primary-strong">
                        {initials(workspace.name)}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                        {workspace.name}
                    </span>
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                    <DropdownMenuGroup>
                        <DropdownMenuLabel>
                            {t("layout.yourWorkspaces")}
                        </DropdownMenuLabel>
                        {workspaces.map((ws) => (
                            <DropdownMenuItem
                                key={ws.id}
                                onClick={() =>
                                    router.post(
                                        route(
                                            "workspaces.switch",
                                            ws.id,
                                        ),
                                    )
                                }
                            >
                                {ws.name}
                                {ws.id === workspace.id ? (
                                    <span
                                        aria-hidden
                                        className="ml-auto text-primary"
                                    >
                                        •
                                    </span>
                                ) : null}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onClick={() => setCreatingWorkspace(true)}
                    >
                        <Plus aria-hidden />
                        {t("layout.createWorkspace")}…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <FormDialog
                open={creatingWorkspace}
                onOpenChange={setCreatingWorkspace}
                title={t("layout.createWorkspace")}
                description={t("layout.createWorkspaceDescription")}
                submitLabel={t("layout.createWorkspace")}
                processing={workspaceForm.processing}
                onSubmit={submitWorkspace}
            >
                <div className="grid gap-2">
                    <Label htmlFor="new-workspace-name">{t("common.name")}</Label>
                    <Input
                        id="new-workspace-name"
                        name="name"
                        placeholder="Studio Nova"
                        autoFocus
                        value={workspaceForm.data.name}
                        onChange={(event) =>
                            workspaceForm.setData(
                                "name",
                                event.target.value,
                            )
                        }
                        aria-invalid={workspaceForm.errors.name ? true : undefined}
                    />
                    {workspaceForm.errors.name ? (
                        <p className="text-sm text-destructive">
                            {workspaceForm.errors.name}
                        </p>
                    ) : null}
                </div>
            </FormDialog>
        </>
    );
}

/** Navigation entries grouped by families. `onNavigate` lets the mobile
 *  drawer close itself after a link tap; the desktop sidebar omits it. */
function NavLinks({ onNavigate }: { onNavigate?: () => void }) {
    const { unreadMentions } = usePage<PageSharedProps>().props;
    const { url } = usePage();
    const { t } = useTranslation();

    const isActive = (item: NavItem) => {
        if (item.prefix === "/") return url === "/";
        // Knowledge stays active on its sub-pages, except the trash
        // which has its own nav entry.
        if (item.route === "knowledge.index") {
            return url.startsWith("/knowledge") && !url.startsWith("/knowledge/trash");
        }
        return url.startsWith(item.prefix);
    };

    return (
        <>
            {NAV_SECTIONS.map((section, sectionIndex) => {
                const items = section.items.map((item) => {
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.label}
                            href={route(item.route)}
                            onClick={onNavigate}
                            aria-current={
                                isActive(item) ? "page" : undefined
                            }
                            className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground aria-[current=page]:bg-secondary aria-[current=page]:font-medium aria-[current=page]:text-foreground"
                        >
                            <Icon className="size-4 shrink-0" aria-hidden />
                            <span>
                                {t(item.label)}
                            </span>
                            {item.badge === "unreadMentions" &&
                            unreadMentions > 0 ? (
                                <span
                                    data-test="mentions-badge"
                                    title={t("mentions.title")}
                                    className="ml-auto grid min-w-5 shrink-0 place-items-center rounded-full bg-primary px-1.5 text-[10px] font-semibold leading-4 text-primary-foreground"
                                >
                                    {unreadMentions}
                                </span>
                            ) : null}
                        </Link>
                    );
                });

                // Labelled families get a mono section label above their
                // links, in the sidebar as in the mobile drawer.
                if (!section.label) {
                    // Flat entries stay direct children of the nav.
                    return items.map((item, itemIndex) => (
                        <Fragment key={`ungrouped-${sectionIndex}-${itemIndex}`}>{item}</Fragment>
                    ));
                }

                return (
                    <section
                        key={section.label}
                        data-test="nav-section"
                        className="flex w-full flex-col gap-1.5"
                    >
                        <p className="px-2 pt-3.5 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                            {t(section.label)}
                        </p>
                        {items}
                    </section>
                );
            })}
        </>
    );
}

/** Language switcher + account menu, pinned to the bottom of the sidebar
 *  and of the mobile drawer. */
function AccountBlock() {
    const { auth, workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    if (!auth.user) {
        return null;
    }

    return (
        <div className="mt-auto flex flex-col gap-1 border-t px-2 pt-3 pb-3.5">
            <LanguageSwitcher />
            <DropdownMenu>
                <DropdownMenuTrigger
                    aria-label={t("layout.accountMenu")}
                    className="flex w-full items-center gap-2.5 rounded-lg py-1 text-left"
                >
                    <span className="grid size-6.5 shrink-0 place-items-center rounded-full border border-primary/25 bg-primary/20 text-[10px] font-semibold tracking-wide text-primary-strong">
                        {initials(auth.user.name)}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-[13px] font-medium">
                            {auth.user.name}
                        </span>
                        <span className="block text-[11px] text-muted-foreground">
                            {t(
                                `layout.roles.${workspace?.role ?? "member"}`,
                            )}
                        </span>
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    side="top"
                    className="w-48"
                >
                    <DropdownMenuGroup>
                        <DropdownMenuLabel>
                            {auth.user.email}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            onClick={() =>
                                router.post(route("logout"))
                            }
                        >
                            <LogOut aria-hidden />
                            {t("layout.logOut")}
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

/** Maquette shell: fixed sidebar (brand, workspace switcher, nav, user) +
 *  sticky pagebar (title, subtitle, actions) above the page body. On mobile
 *  the sidebar becomes a drawer opened from the pagebar's menu button. */
export default function AppLayout({
    title,
    subtitle,
    leading,
    actions,
    narrow,
    children,
}: AppLayoutProps) {
    const { auth } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="grid min-h-dvh grid-cols-1 bg-background lg:grid-cols-[232px_1fr]">
            <aside className="hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col lg:gap-1 lg:overflow-y-auto lg:border-r lg:bg-sidebar lg:px-3 lg:text-sidebar-foreground">
                <div className="flex items-center gap-2.5 px-2 pt-4 pb-3.5">
                    <img
                        src="/assets/taskr-app-icon.svg"
                        alt=""
                        aria-hidden
                        className="size-6.5 shrink-0 rounded-[7px]"
                    />
                    <span className="text-[15px] font-semibold tracking-tight">
                        Taskr
                    </span>
                </div>

                <WorkspaceSwitcher />

                <p className="px-2 pt-3.5 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                    {t("layout.workspaceLabel")}
                </p>

                <nav
                    className="flex w-full flex-col items-start gap-1.5"
                    aria-label={t("nav.mainNav")}
                >
                    <NavLinks />
                </nav>

                {auth.user ? <AccountBlock /> : null}
            </aside>

            <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                <SheetContent side="left" className="p-0" data-test="mobile-nav-drawer">
                    <div className="flex items-center gap-2.5 pl-[max(1rem,env(safe-area-inset-left))] pr-4 pt-[max(1rem,env(safe-area-inset-top))] pb-3.5">
                        <img
                            src="/assets/taskr-app-icon.svg"
                            alt=""
                            aria-hidden
                            className="size-6.5 shrink-0 rounded-[7px]"
                        />
                        <span className="text-[15px] font-semibold tracking-tight">
                            Taskr
                        </span>
                    </div>

                    <div className="pl-[max(0.75rem,env(safe-area-inset-left))] pr-3">
                        <WorkspaceSwitcher />
                    </div>

                    <p className="pl-[max(1rem,env(safe-area-inset-left))] pr-4 pt-3.5 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                        {t("layout.workspaceLabel")}
                    </p>

                    <nav
                        className="flex w-full flex-col items-start gap-1.5 pl-[max(0.75rem,env(safe-area-inset-left))] pr-3 pb-[max(1rem,env(safe-area-inset-bottom))]"
                        aria-label={t("nav.mainNav")}
                    >
                        <NavLinks onNavigate={() => setMenuOpen(false)} />
                    </nav>

                    {auth.user ? <AccountBlock /> : null}
                </SheetContent>
            </Sheet>

            <div className="flex min-w-0 flex-col">
                {title || actions || leading ? (
                    <header className="sticky top-0 z-20 border-b bg-background/88 backdrop-blur">
                        <div className="flex flex-wrap items-center gap-3 pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[max(0.75rem,env(safe-area-inset-top))] pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:gap-4 lg:px-7 lg:py-3.5">
                            <div className="flex min-w-0 flex-wrap items-center gap-2.5">
                                {auth.user ? (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="-ml-2 lg:hidden"
                                        aria-label={t("layout.menu")}
                                        aria-haspopup="dialog"
                                        onClick={() => setMenuOpen(true)}
                                        data-test="mobile-menu-button"
                                    >
                                        <Menu aria-hidden />
                                    </Button>
                                ) : null}
                                {leading}
                                {title ? (
                                    <h1 className="flex flex-wrap items-center gap-2.5 text-[17px] leading-tight font-semibold tracking-tight">
                                        {title}
                                    </h1>
                                ) : null}
                                {subtitle ? (
                                    <span className="hidden text-sm text-muted-foreground sm:inline">
                                        {subtitle}
                                    </span>
                                ) : null}
                            </div>
                            {actions ? (
                                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:flex-1 lg:justify-end lg:gap-4">
                                    {actions}
                                </div>
                            ) : null}
                        </div>
                    </header>
                ) : null}

                <main
                    className={`mx-auto w-full pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[max(1.75rem,env(safe-area-inset-top))] pb-[max(1.75rem,env(safe-area-inset-bottom))] lg:px-7 ${narrow ? "max-w-[820px]" : "max-w-[1280px]"}`}
                >
                    {children}
                </main>
            </div>
        </div>
    );
}
