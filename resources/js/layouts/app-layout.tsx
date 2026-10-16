import { useState, type FormEvent, type ReactNode } from "react";
import { FormDialog } from "@/components/form-dialog";
import { initials } from "@/components/issue-badges";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
    BookOpen,
    ChevronDown,
    Folder,
    Home,
    ListChecks,
    LogOut,
    Plus,
    SlidersHorizontal,
} from "lucide-react";
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

const NAV_ITEMS = [
    { label: "nav.home", route: "home", prefix: "/", icon: Home },
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
    {
        label: "nav.knowledge",
        route: "knowledge.index",
        prefix: "/knowledge",
        icon: BookOpen,
    },
    {
        label: "nav.settings",
        route: "settings.statuses",
        prefix: "/settings",
        icon: SlidersHorizontal,
    },
];

/** Maquette shell: fixed sidebar (brand, workspace switcher, nav, user) +
 * sticky pagebar (title, subtitle, actions) above the page body. */
export default function AppLayout({
    title,
    subtitle,
    leading,
    actions,
    narrow,
    children,
}: AppLayoutProps) {
    const { auth, workspace, workspaces } = usePage<PageSharedProps>().props;
    const { url } = usePage();
    const { t } = useTranslation();

    const isActive = (prefix: string) =>
        prefix === "/" ? url === "/" : url.startsWith(prefix);

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

    return (
        <div className="grid min-h-dvh grid-cols-1 bg-background lg:grid-cols-[232px_1fr]">
            <aside className="flex gap-1 overflow-x-auto border-b bg-sidebar px-3.5 py-2.5 text-sidebar-foreground max-lg:items-center lg:sticky lg:top-0 lg:h-dvh lg:flex-col lg:gap-1 lg:overflow-y-auto lg:border-b-0 lg:border-r lg:px-3 lg:py-0">
                <div className="flex items-center gap-2.5 px-2 pt-4 pb-3.5 max-lg:p-0">
                    <span className="grid size-6.5 shrink-0 place-items-center rounded-[7px] bg-primary text-sm font-bold text-primary-foreground">
                        T
                    </span>
                    <span className="text-[15px] font-semibold tracking-tight">
                        Taskr
                    </span>
                </div>

                {workspace ? (
                    <>
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                aria-label={t("layout.switchWorkspace")}
                                className="flex w-full items-center gap-2 rounded-lg border bg-card px-2.5 py-2 text-left shadow-xs transition-colors hover:bg-accent max-lg:w-auto max-lg:shrink-0"
                            >
                                <span className="grid size-5.5 shrink-0 place-items-center rounded-md bg-primary/25 text-[11px] font-bold text-primary">
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
                ) : null}

                <p className="px-2 pt-3.5 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground max-lg:hidden">
                    {t("layout.workspaceLabel")}
                </p>

                <nav
                    className="flex items-start gap-1.5 max-lg:flex-none lg:flex-col"
                    aria-label={t("nav.mainNav")}
                >
                    {NAV_ITEMS.map((item) => {
                        const Icon = item.icon;
                        return (
                            <Link
                                key={item.label}
                                href={route(item.route)}
                                aria-current={
                                    isActive(item.prefix) ? "page" : undefined
                                }
                                className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground aria-[current=page]:bg-secondary aria-[current=page]:font-medium aria-[current=page]:text-foreground max-lg:px-2.5 max-lg:py-2"
                            >
                                <Icon className="size-4 shrink-0" aria-hidden />
                                <span className="max-lg:hidden">
                                    {t(item.label)}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                {auth.user ? (
                    <div className="mt-auto flex items-center gap-1 max-lg:ml-auto max-lg:p-0 lg:flex-col lg:border-t lg:px-2 lg:pt-3 lg:pb-3.5">
                        <LanguageSwitcher />
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                aria-label={t("layout.accountMenu")}
                                className="flex w-full items-center gap-2.5 rounded-lg py-1 text-left max-lg:w-auto max-lg:px-1"
                            >
                                <span className="grid size-6.5 shrink-0 place-items-center rounded-full border border-primary/25 bg-primary/20 text-[10px] font-semibold tracking-wide text-primary">
                                    {initials(auth.user.name)}
                                </span>
                                <span className="min-w-0 max-lg:hidden">
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
                ) : null}
            </aside>

            <div className="flex min-w-0 flex-col">
                {title || actions || leading ? (
                    <header className="sticky top-0 z-20 flex flex-wrap items-center gap-4 border-b bg-background/88 px-4 py-3.5 backdrop-blur lg:px-7">
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
                        <span className="flex-1" />
                        {actions}
                    </header>
                ) : null}

                <main
                    className={`mx-auto w-full px-4 py-7 lg:px-7 ${narrow ? "max-w-[820px]" : "max-w-[1280px]"}`}
                >
                    {children}
                </main>
            </div>
        </div>
    );
}
