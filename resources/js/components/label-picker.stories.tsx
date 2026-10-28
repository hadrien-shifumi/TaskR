/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * LabelPicker is controlled (`selectedIds` + `onToggle`), so the stories use
 * a small toggle harness. Fixture labels carry the full LabelRef payload —
 * the picker itself only reads id/name.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState } from 'react';

import { LabelPicker } from '@/components/label-picker';
import type { LabelRef } from '@/types';

const LABELS: LabelRef[] = [
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A31', name: 'Bug', color: 'red' },
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A33', name: 'Design', color: 'violet' },
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A35', name: 'Documentation', color: 'sky' },
    { id: '01JC0D5K8M2P4R6T8V0X2Z4A37', name: 'Performance', color: 'amber' },
];

function LabelPickerHarness({
    initialSelectedIds = [],
    idPrefix,
}: {
    initialSelectedIds?: string[];
    idPrefix?: string;
}) {
    const [selectedIds, setSelectedIds] = useState<string[]>(initialSelectedIds);

    return (
        <div className="grid max-w-md gap-3">
            <LabelPicker
                labels={LABELS}
                selectedIds={selectedIds}
                onToggle={(labelId) => {
                    setSelectedIds((current) =>
                        current.includes(labelId)
                            ? current.filter((id) => id !== labelId)
                            : [...current, labelId],
                    );
                }}
                idPrefix={idPrefix}
            />
            <span className="font-mono text-xs text-muted-foreground">
                selectedIds: [{selectedIds.map((id) => ` ${id.slice(-4)}`).join(',') || ' '}]
            </span>
        </div>
    );
}

const meta = {
    title: 'Components/LabelPicker',
    component: LabelPicker,
    tags: ['autodocs'],
    args: {
        labels: LABELS,
        selectedIds: [],
        onToggle: () => {},
    },
    argTypes: {
        selectedIds: { control: false, description: 'Controlled by the form — the stories use a harness.' },
        onToggle: { control: false },
        labels: { control: false },
    },
} satisfies Meta<typeof LabelPicker>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Interactive: Story = {
    render: () => <LabelPickerHarness />,
};

export const Preselected: Story = {
    name: 'Preselected labels',
    render: () => <LabelPickerHarness initialSelectedIds={['01JC0D5K8M2P4R6T8V0X2Z4A31', '01JC0D5K8M2P4R6T8V0X2Z4A35']} />,
};

export const WithIdPrefix: Story = {
    name: 'With idPrefix (label htmlFor)',
    render: () => (
        <div className="grid max-w-md gap-2">
            <span className="text-sm font-medium">Labels</span>
            <LabelPickerHarness idPrefix="story-label" />
        </div>
    ),
};

export const EmptyWorkspace: Story = {
    name: 'No labels yet',
    render: () => <LabelPicker labels={[]} selectedIds={[]} onToggle={() => {}} />,
};
