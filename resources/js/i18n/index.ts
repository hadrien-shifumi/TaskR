import i18next, { type i18n as I18nInstance } from "i18next";
import { initReactI18next } from "react-i18next";

import en from "./en";
import fr from "./fr";

export const SUPPORTED_LOCALES = ["en", "fr"] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

const resources = {
    en: { translation: en },
    fr: { translation: fr },
};

/**
 * Initializes the singleton i18next instance with the server-resolved locale
 * (the authenticated user's preference, or the application default for
 * guests). Resources are bundled, so initialization is synchronous in
 * practice; `useSuspense: false` keeps pages render-safe regardless.
 */
export function initI18n(locale: string): I18nInstance {
    void i18next.use(initReactI18next).init({
        resources,
        lng: locale,
        fallbackLng: "en",
        interpolation: { escapeValue: false },
        react: { useSuspense: false },
    });

    return i18next;
}

export default i18next;
