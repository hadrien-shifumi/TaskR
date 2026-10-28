/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * LanguageSwitcher reads the mocked `usePage` and fires a no-op
 * `router.patch` in Storybook; the optimistic `i18n.changeLanguage` still
 * runs, so picking a language really switches the gallery (the Locale
 * toolbar stays the source of truth for translations).
 */
import type { Meta, StoryObj } from '@storybook/react';

import { LanguageSwitcher } from '@/components/language-switcher';

const meta = {
    title: 'Components/LanguageSwitcher',
    component: LanguageSwitcher,
    tags: ['autodocs'],
} satisfies Meta<typeof LanguageSwitcher>;

export default meta;
type Story = StoryObj<typeof meta>;

export const InSidebar: Story = {
    name: 'In the sidebar width',
    render: () => (
        <div className="w-60">
            <LanguageSwitcher />
        </div>
    ),
};
