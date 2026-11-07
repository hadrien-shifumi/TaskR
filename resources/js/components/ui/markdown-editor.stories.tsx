import type { Meta, StoryObj } from '@storybook/react'
import { useState } from 'react'

import { MarkdownEditor } from '@/components/ui/markdown-editor'
import { MarkdownView } from '@/components/ui/markdown-view'

const SAMPLE = `## Launch checklist

Everything **must** ship before the _end of sprint_.

- [x] Draft the changelog
- [ ] QA pass on the onboarding flow
- [ ] Update the screenshots

> Blocked until the API returns normalized dates.

\`\`\`bash
npm run test:e2e
\`\`\`
`

const meta = {
  title: 'UI/MarkdownEditor',
  component: MarkdownEditor,
  tags: ['autodocs'],
  args: {
    value: SAMPLE,
    onChange: () => {},
  },
} satisfies Meta<typeof MarkdownEditor>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Editable: Story = {
  render: () => {
    const [value, setValue] = useState(SAMPLE)

    return (
      <div className="max-w-2xl">
        <MarkdownEditor value={value} onChange={setValue} />
        <details className="mt-3">
          <summary className="cursor-pointer text-sm text-muted-foreground">
            Markdown source
          </summary>
          <pre className="mt-2 rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">
            {value}
          </pre>
        </details>
      </div>
    )
  },
}

export const Empty: Story = {
  render: () => {
    const [value, setValue] = useState('')

    return (
      <div className="max-w-2xl">
        <MarkdownEditor value={value} onChange={setValue} />
      </div>
    )
  },
}

export const ReadOnly: Story = {
  render: () => (
    <div className="max-w-2xl">
      <MarkdownView value={SAMPLE} />
    </div>
  ),
}
