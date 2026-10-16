import { router, usePage } from "@inertiajs/react";
import { Languages } from "lucide-react";
import { useTranslation } from "react-i18next";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import i18n from "@/i18n";
import type { PageSharedProps } from "@/types";

/** Each language is shown in its own name; no translation keys needed. */
const LOCALES = [
    { value: "en", label: "English" },
    { value: "fr", label: "Français" },
];

/** Layout control persisting the interface language (users.locale). */
export function LanguageSwitcher() {
    usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const changeLocale = (value: string) => {
        // Optimistic: the Inertia response re-syncs via the global
        // `router.on('success')` listener in app.tsx.
        void i18n.changeLanguage(value);
        router.patch(route("user.locale.update"), { locale: value }, {
            preserveScroll: true,
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={t("layout.language")}
                className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground max-lg:w-auto max-lg:px-2 max-lg:py-2"
            >
                <Languages className="size-4 shrink-0" aria-hidden />
                <span className="max-lg:hidden">
                    {LOCALES.find((l) => l.value === i18n.language)?.label ?? i18n.language}
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="w-40">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{t("layout.language")}</DropdownMenuLabel>
                    {LOCALES.map((locale) => (
                        <DropdownMenuItem
                            key={locale.value}
                            onClick={() => changeLocale(locale.value)}
                        >
                            {locale.label}
                            {i18n.language === locale.value ? (
                                <span aria-hidden className="ml-auto text-primary">
                                    •
                                </span>
                            ) : null}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
