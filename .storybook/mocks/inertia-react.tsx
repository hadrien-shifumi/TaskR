import type { PageSharedProps } from '@/types';
import { useState, type AnchorHTMLAttributes, type ReactNode } from 'react';

/**
 * Storybook stand-in for `@inertiajs/react`, wired through a `resolve.alias`
 * in `.storybook/main.ts`. There is no Laravel backend behind Storybook, so
 * this mock provides just enough surface for app components to render:
 *
 * - `Link` renders a plain anchor (Inertia-only props are ignored).
 * - `usePage` reads from a mutable shared-props object — stories override it
 *   with `setInertiaSharedProps`.
 * - `router` and the `useForm` verbs are no-ops: navigation and persistence
 *   do not exist in the gallery. `useForm` keeps working local state so
 *   inputs stay interactive.
 * - `Head` renders nothing (no document head management).
 */

const noop = () => {};

const defaultWorkspace = {
    id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    name: 'Studio Nova',
    role: 'owner',
} as const;

let sharedProps: PageSharedProps = {
    auth: {
        user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', locale: 'en' },
    },
    workspace: defaultWorkspace,
    workspaces: [defaultWorkspace],
    flash: {},
    locale: 'en',
    unreadMentions: 0,
    mentionables: [],
};

/**
 * Merges overrides into the shared page props returned by `usePage`. Stories
 * call this (typically from a decorator) to simulate another workspace, a
 * flash message, a locale, ...
 */
export function setInertiaSharedProps(partial: Partial<PageSharedProps>): void {
    sharedProps = { ...sharedProps, ...partial };
}

export function usePage<T = PageSharedProps>(): { props: T; url: string } {
    return { props: sharedProps as unknown as T, url: '/' };
}

interface LinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
    href: string;
    children?: ReactNode;
}

export function Link({ href, children, ...rest }: LinkProps) {
    return (
        <a href={href} {...rest}>
            {children}
        </a>
    );
}

export const router = {
    get: noop,
    post: noop,
    put: noop,
    patch: noop,
    delete: noop,
    reload: noop,
    on: noop,
    off: noop,
};

export function useForm<TForm extends Record<string, unknown>>(initialValues: TForm) {
    const [data, setDataState] = useState<TForm>({ ...initialValues });

    const setData = (
        keyOrValues: string | Partial<TForm> | ((previous: TForm) => TForm),
        value?: unknown,
    ): void => {
        setDataState((current) => {
            if (typeof keyOrValues === 'function') {
                return keyOrValues(current);
            }

            const patch = typeof keyOrValues === 'string' ? { [keyOrValues]: value } : keyOrValues;

            return { ...current, ...patch } as TForm;
        });
    };

    const form = {
        data,
        setData,
        errors: {} as Partial<Record<keyof TForm & string, string>>,
        processing: false,
        post: noop,
        patch: noop,
        put: noop,
        delete: noop,
        reset: noop,
        setError: noop,
        clearErrors: noop,
    };

    // `transform` returns the form (chainable, like the real useForm). It is
    // attached on return so the form object can reference itself without a
    // circular initializer.
    return {
        ...form,
        transform: (_callback: (data: TForm) => TForm) => form,
    };
}

export function Head(_props: { children?: ReactNode; title?: string }): ReactNode {
    return null;
}
