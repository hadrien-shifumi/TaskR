import * as React from "react"
import { useEffect, useState } from "react"
import { EditorContent, useEditor, useEditorState, type Editor } from "@tiptap/react"
import { StarterKit } from "@tiptap/starter-kit"
import { TaskItem, TaskList } from "@tiptap/extension-list"
import { TableKit } from "@tiptap/extension-table"
import { Placeholder } from "@tiptap/extensions"
import { Markdown, type MarkdownStorage } from "tiptap-markdown"
import {
  BoldIcon,
  CodeIcon,
  CodeXmlIcon,
  Heading1Icon,
  Heading2Icon,
  Heading3Icon,
  ItalicIcon,
  Link2OffIcon,
  LinkIcon,
  ListIcon,
  ListOrderedIcon,
  ListTodoIcon,
  MinusIcon,
  QuoteIcon,
  Redo2Icon,
  StrikethroughIcon,
  TableIcon,
  UnderlineIcon,
  Undo2Icon,
} from "lucide-react"
import { cn } from "cn"

import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"

const extensions = [
  StarterKit.configure({
    link: { openOnClick: false },
  }),
  TaskList,
  TaskItem.configure({ nested: true }),
  TableKit,
  Placeholder.configure({ placeholder: "Write in markdown…" }),
  Markdown.configure({
    html: false,
    linkify: true,
    transformPastedText: true,
    transformCopiedText: true,
  }),
]

const prose = cn(
  "*:first-child:mt-0 *:last-child:mb-0",
  "[&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-2",
  "[&_blockquote]:my-2 [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground",
  "[&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-xs",
  "[&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre_code]:bg-transparent [&_pre_code]:px-0 [&_pre_code]:py-0",
  "[&_h1]:mb-2 [&_h1]:mt-5 [&_h1]:text-lg [&_h1]:font-semibold",
  "[&_h2]:mb-2 [&_h2]:mt-5 [&_h2]:font-semibold",
  "[&_h3]:mb-1 [&_h3]:mt-3 [&_h3]:font-semibold",
  "[&_hr]:my-3 [&_hr]:border-border",
  "[&_img]:max-w-full [&_img]:rounded-md",
  "[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5",
  "[&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5",
  "[&_li]:my-0.5 [&_li>p]:my-0",
  "[&_p]:my-2 [&_p]:leading-relaxed",
  "[&_table]:my-3 [&_table]:w-full [&_table]:border-collapse",
  "[&_th]:border [&_th]:border-border [&_th]:bg-muted/50 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_th]:font-medium",
  "[&_td]:border [&_td]:border-border [&_td]:px-2 [&_td]:py-1",
  "[&_[data-type=taskItem]]:my-1 [&_[data-type=taskItem]]:flex [&_[data-type=taskItem]]:items-start [&_[data-type=taskItem]]:gap-2",
  "[&_[data-type=taskItem]_label]:mt-0.5 [&_[data-type=taskItem]_label]:shrink-0",
  "[&_[data-type=taskItem]_div]:min-w-0 [&_[data-type=taskItem]_div]:flex-1",
  "[&_[data-type=taskList]]:list-none [&_[data-type=taskList]]:my-2 [&_[data-type=taskList]]:pl-0",
  "[&_input]:size-3.5 [&_input]:accent-primary"
)

function toMarkdown(editor: Editor): string {
  const storage = editor.storage as unknown as { markdown: MarkdownStorage }
  return storage.markdown.getMarkdown()
}

/**
 * Mounts a Tiptap document fed from a markdown string and kept in sync with it.
 * Pass `onChange` for an editable editor that reports markdown back.
 */
function useMarkdownDocument(value: string, onChange?: (markdown: string) => void) {
  const editor = useEditor({
    extensions,
    content: value,
    editable: onChange !== undefined,
    onUpdate: onChange
      ? ({ editor }) => onChange(toMarkdown(editor))
      : undefined,
  })

  useEffect(() => {
    if (!editor || editor.isDestroyed) {
      return
    }
    if (value !== toMarkdown(editor)) {
      editor.commands.setContent(value, { emitUpdate: false })
    }
  }, [editor, value])

  return editor
}

function ToolbarButton({
  label,
  active = false,
  ...props
}: React.ComponentProps<typeof Button> & { label: string; active?: boolean }) {
  return (
    <Button
      variant="ghost"
      size="icon-sm"
      title={label}
      aria-label={label}
      aria-pressed={active}
      className={cn("text-muted-foreground", active && "bg-muted text-foreground")}
      {...props}
    />
  )
}

function Toolbar({ editor }: { editor: Editor }) {
  const [linkUrl, setLinkUrl] = useState("")
  const [showLinkInput, setShowLinkInput] = useState(false)

  const state = useEditorState({
    editor,
    selector: ({ editor }) => ({
      canUndo: editor.can().undo(),
      canRedo: editor.can().redo(),
      heading1: editor.isActive("heading", { level: 1 }),
      heading2: editor.isActive("heading", { level: 2 }),
      heading3: editor.isActive("heading", { level: 3 }),
      bold: editor.isActive("bold"),
      italic: editor.isActive("italic"),
      underline: editor.isActive("underline"),
      strike: editor.isActive("strike"),
      code: editor.isActive("code"),
      bulletList: editor.isActive("bulletList"),
      orderedList: editor.isActive("orderedList"),
      taskList: editor.isActive("taskList"),
      blockquote: editor.isActive("blockquote"),
      codeBlock: editor.isActive("codeBlock"),
      link: editor.isActive("link"),
    }),
  })

  const chain = () => editor.chain().focus()

  const openLinkInput = () => {
    setLinkUrl((editor.getAttributes("link").href as string | undefined) ?? "")
    setShowLinkInput(true)
  }

  const applyLink = (event: React.FormEvent) => {
    event.preventDefault()
    const href = linkUrl.trim()
    if (href) {
      chain().extendMarkRange("link").setLink({ href }).run()
    } else {
      chain().extendMarkRange("link").unsetLink().run()
    }
    setShowLinkInput(false)
    setLinkUrl("")
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-0.5 border-b bg-muted/40 px-1 py-1">
        <ToolbarButton label="Undo" disabled={!state.canUndo} onClick={() => chain().undo().run()}>
          <Undo2Icon />
        </ToolbarButton>
        <ToolbarButton label="Redo" disabled={!state.canRedo} onClick={() => chain().redo().run()}>
          <Redo2Icon />
        </ToolbarButton>
        <div className="mx-1 h-4 w-px shrink-0 bg-border" />
        <ToolbarButton label="Heading 1" active={state.heading1} onClick={() => chain().toggleHeading({ level: 1 }).run()}>
          <Heading1Icon />
        </ToolbarButton>
        <ToolbarButton label="Heading 2" active={state.heading2} onClick={() => chain().toggleHeading({ level: 2 }).run()}>
          <Heading2Icon />
        </ToolbarButton>
        <ToolbarButton label="Heading 3" active={state.heading3} onClick={() => chain().toggleHeading({ level: 3 }).run()}>
          <Heading3Icon />
        </ToolbarButton>
        <div className="mx-1 h-4 w-px shrink-0 bg-border" />
        <ToolbarButton label="Bold" active={state.bold} onClick={() => chain().toggleBold().run()}>
          <BoldIcon />
        </ToolbarButton>
        <ToolbarButton label="Italic" active={state.italic} onClick={() => chain().toggleItalic().run()}>
          <ItalicIcon />
        </ToolbarButton>
        <ToolbarButton label="Underline" active={state.underline} onClick={() => chain().toggleUnderline().run()}>
          <UnderlineIcon />
        </ToolbarButton>
        <ToolbarButton label="Strikethrough" active={state.strike} onClick={() => chain().toggleStrike().run()}>
          <StrikethroughIcon />
        </ToolbarButton>
        <ToolbarButton label="Inline code" active={state.code} onClick={() => chain().toggleCode().run()}>
          <CodeIcon />
        </ToolbarButton>
        <div className="mx-1 h-4 w-px shrink-0 bg-border" />
        <ToolbarButton label="Bullet list" active={state.bulletList} onClick={() => chain().toggleBulletList().run()}>
          <ListIcon />
        </ToolbarButton>
        <ToolbarButton label="Numbered list" active={state.orderedList} onClick={() => chain().toggleOrderedList().run()}>
          <ListOrderedIcon />
        </ToolbarButton>
        <ToolbarButton label="Task list" active={state.taskList} onClick={() => chain().toggleTaskList().run()}>
          <ListTodoIcon />
        </ToolbarButton>
        <div className="mx-1 h-4 w-px shrink-0 bg-border" />
        <ToolbarButton label="Quote" active={state.blockquote} onClick={() => chain().toggleBlockquote().run()}>
          <QuoteIcon />
        </ToolbarButton>
        <ToolbarButton label="Code block" active={state.codeBlock} onClick={() => chain().toggleCodeBlock().run()}>
          <CodeXmlIcon />
        </ToolbarButton>
        <ToolbarButton label="Divider" onClick={() => chain().setHorizontalRule().run()}>
          <MinusIcon />
        </ToolbarButton>
        <div className="mx-1 h-4 w-px shrink-0 bg-border" />
        <ToolbarButton label="Link" active={state.link} onClick={openLinkInput}>
          <LinkIcon />
        </ToolbarButton>
        <ToolbarButton label="Remove link" disabled={!state.link} onClick={() => chain().unsetLink().run()}>
          <Link2OffIcon />
        </ToolbarButton>
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button
                variant="ghost"
                size="icon-sm"
                title="Table"
                aria-label="Table"
                aria-haspopup="menu"
                className="text-muted-foreground"
              />
            }
          >
            <TableIcon />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start">
            <DropdownMenuItem onClick={() => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}>
              Insert table
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => chain().addRowAfter().run()}>Add row</DropdownMenuItem>
            <DropdownMenuItem onClick={() => chain().addColumnAfter().run()}>Add column</DropdownMenuItem>
            <DropdownMenuItem onClick={() => chain().deleteRow().run()}>Delete row</DropdownMenuItem>
            <DropdownMenuItem onClick={() => chain().deleteColumn().run()}>Delete column</DropdownMenuItem>
            <DropdownMenuItem variant="destructive" onClick={() => chain().deleteTable().run()}>
              Delete table
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
      {showLinkInput ? (
        <form className="flex items-center gap-2 border-b bg-muted/20 px-2 py-1.5" onSubmit={applyLink}>
          <Input
            type="url"
            value={linkUrl}
            onChange={(event) => setLinkUrl(event.target.value)}
            placeholder="https://example.com"
            autoFocus
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                setShowLinkInput(false)
              }
            }}
            className="h-7"
          />
          <Button type="submit" size="sm">
            Apply
          </Button>
          <Button type="button" variant="ghost" size="sm" onClick={() => setShowLinkInput(false)}>
            Cancel
          </Button>
        </form>
      ) : null}
    </>
  )
}

/**
 * Full markdown WYSIWYG editor. The value is a plain markdown string.
 */
function MarkdownEditor({
  value,
  onChange,
  className,
}: {
  value: string
  onChange: (markdown: string) => void
  className?: string
}) {
  const editor = useMarkdownDocument(value, onChange)

  return (
    <div
      data-slot="markdown-editor"
      className={cn(
        "rounded-lg border border-input focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 dark:bg-input/30",
        className
      )}
    >
      {editor ? (
        <Toolbar editor={editor} />
      ) : (
        <div className="h-[36px] border-b bg-muted/40" aria-hidden="true" />
      )}
      <EditorContent
        editor={editor}
        className={cn(
          "px-3 py-2 text-sm",
          "[&_.ProseMirror]:min-h-48 [&_.ProseMirror]:outline-none",
          "[&_.is-empty:first-child::before]:content-(attr(data-placeholder)) [&_.is-empty:first-child::before]:float-left [&_.is-empty:first-child::before]:h-0 [&_.is-empty:first-child::before]:pointer-events-none [&_.is-empty:first-child::before]:text-muted-foreground",
          prose
        )}
      />
    </div>
  )
}

/**
 * Read-only markdown renderer sharing the editor document schema.
 */
function MarkdownView({ value, className }: { value: string; className?: string }) {
  const editor = useMarkdownDocument(value)

  if (!editor) {
    return null
  }

  return <EditorContent editor={editor} className={cn("text-sm", prose, className)} />
}

export { MarkdownEditor, MarkdownView }
