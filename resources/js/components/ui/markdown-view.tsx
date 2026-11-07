import * as React from "react"
import { cn } from "cn"

/**
 * Shared typography classes for rendered markdown. Kept in sync with the
 * editor's ProseMirror output so the read-only view is pixel-identical:
 * the selectors target plain HTML elements and the same data attributes
 * this renderer emits (`data-type="taskList"` / `"taskItem"`).
 */
export const markdownProse = cn(
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

/**
 * Safe URL scheme filter for link and image targets: only documents the
 * editor can produce are kept, so a crafted `[x](javascript:…)` body is
 * neutralized instead of rendered as a working link.
 */
function safeUrl(url: string): string | null {
  const trimmed = url.trim()
  if (/^(https?:\/\/|mailto:)/i.test(trimmed)) {
    return trimmed
  }
  if (/^\/[^/]/.test(trimmed) || trimmed.startsWith("#")) {
    return trimmed
  }
  return null
}

type InlineToken =
  | { kind: "text"; text: string }
  | { kind: "code"; text: string }
  | { kind: "bold"; children: string }
  | { kind: "italic"; children: string }
  | { kind: "strike"; children: string }
  | { kind: "link"; children: string; url: string }
  | { kind: "image"; alt: string; url: string }
  | { kind: "url"; url: string }

const INLINE_RE =
  /(`[^`\n]+`)|!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]*)\]\(([^)\s]+)\)|(\*\*(?:(?!\*\*).)+\*\*)|(__(?:(?!__).)+__)|(~~(?:(?!~~).)+~~)|(\*(?:(?!\*).)+\*)|(_(?:(?!_).)+_)|(https?:\/\/[^\s)<]+)/g

/** Splits inline markdown into tokens; emphasis contents are recursed
 * into when rendered, so nested marks (`**bold *italic***`) work. */
function tokenizeInline(text: string): InlineToken[] {
  const tokens: InlineToken[] = []
  let lastIndex = 0
  let match: RegExpExecArray | null
  INLINE_RE.lastIndex = 0

  while ((match = INLINE_RE.exec(text)) !== null) {
    if (match.index > lastIndex) {
      tokens.push({ kind: "text", text: text.slice(lastIndex, match.index) })
    }
    const [full, code, imgAlt, imgUrl, linkText, linkUrl, bold2, boldU, strike, italic1, italicU, bareUrl] = match

    if (code !== undefined) tokens.push({ kind: "code", text: code.slice(1, -1) })
    else if (imgAlt !== undefined) tokens.push({ kind: "image", alt: imgAlt, url: imgUrl })
    else if (linkText !== undefined) tokens.push({ kind: "link", children: linkText, url: linkUrl })
    else if (bold2 !== undefined) tokens.push({ kind: "bold", children: bold2.slice(2, -2) })
    else if (boldU !== undefined) tokens.push({ kind: "bold", children: boldU.slice(2, -2) })
    else if (strike !== undefined) tokens.push({ kind: "strike", children: strike.slice(2, -2) })
    else if (italic1 !== undefined) tokens.push({ kind: "italic", children: italic1.slice(1, -1) })
    else if (italicU !== undefined) tokens.push({ kind: "italic", children: italicU.slice(1, -1) })
    else if (bareUrl !== undefined) tokens.push({ kind: "url", url: bareUrl })

    lastIndex = match.index + full.length
  }

  if (lastIndex < text.length) {
    tokens.push({ kind: "text", text: text.slice(lastIndex) })
  }

  return tokens
}

function renderInline(text: string, keyPrefix: string): React.ReactNode[] {
  return tokenizeInline(text).map((token, index) => {
    const key = `${keyPrefix}-${index}`
    switch (token.kind) {
      case "text":
        return <React.Fragment key={key}>{token.text}</React.Fragment>
      case "code":
        return <code key={key}>{token.text}</code>
      case "bold":
        return <strong key={key}>{renderInline(token.children, key)}</strong>
      case "italic":
        return <em key={key}>{renderInline(token.children, key)}</em>
      case "strike":
        return (
          <s key={key}>
            {renderInline(token.children, key)}
          </s>
        )
      case "link": {
        const href = safeUrl(token.url)
        return href === null ? (
          <React.Fragment key={key}>{token.children}</React.Fragment>
        ) : (
          <a key={key} href={href} target="_blank" rel="noopener noreferrer">
            {renderInline(token.children, key)}
          </a>
        )
      }
      case "image": {
        const src = safeUrl(token.url)
        return src === null ? null : <img key={key} src={src} alt={token.alt} />
      }
      case "url":
        return (
          <a key={key} href={token.url} target="_blank" rel="noopener noreferrer">
            {token.url}
          </a>
        )
    }
  })
}

type Block =
  | { kind: "paragraph"; lines: string[] }
  | { kind: "heading"; level: 1 | 2 | 3; text: string }
  | { kind: "code"; language: string | null; lines: string[] }
  | { kind: "quote"; blocks: Block[] }
  | { kind: "hr" }
  | { kind: "table"; header: string[]; alignment: Array<"left" | "center" | "right">; rows: string[][] }
  | { kind: "list"; ordered: boolean; items: ListItem[] }

type ListItem = {
  /** Paragraph lines of the item body (task marker already stripped). */
  lines: string[]
  checked: boolean | null
  /** Nested lists parsed from deeper-indented following lines. */
  children: ListItem[][]
}

const HEADING_RE = /^(#{1,6})\s+(.*)$/
const HR_RE = /^ {0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/
const UL_ITEM_RE = /^(\s*)[-*]\s+(.*)$/
const OL_ITEM_RE = /^(\s*)\d+[.)]\s+(.*)$/
const TASK_RE = /^\[([ xX])\]\s+(.*)$/
const FENCE_RE = /^ {0,3}(```+|~~~+)\s*(\S*)\s*$/
const TABLE_DIVIDER_RE = /^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/

/** Splits a table row into cells; surrounding pipes are optional. */
function splitRow(line: string): string[] {
  let row = line.trim()
  if (row.startsWith("|")) row = row.slice(1)
  if (row.endsWith("|") && !row.endsWith("\\|")) row = row.slice(0, -1)
  // Split on unescaped pipes.
  return row.split(/(?<!\\)\|/).map((cell) => cell.trim().replace(/\\\|/g, "|"))
}

function parseBlocks(lines: string[]): Block[] {
  const blocks: Block[] = []
  let i = 0

  while (i < lines.length) {
    const line = lines[i]

    if (line.trim() === "") {
      i += 1
      continue
    }

    const fence = line.match(FENCE_RE)
    if (fence !== null) {
      const marker = fence[1]
      const language = fence[2] === "" ? null : fence[2]
      const body: string[] = []
      i += 1
      while (i < lines.length && !lines[i].trimStart().startsWith(marker)) {
        body.push(lines[i])
        i += 1
      }
      i += 1 // closing fence
      blocks.push({ kind: "code", language, lines: body })
      continue
    }

    const heading = line.match(HEADING_RE)
    if (heading !== null) {
      const level = Math.min(3, heading[1].length) as 1 | 2 | 3
      blocks.push({ kind: "heading", level, text: heading[2].trim() })
      i += 1
      continue
    }

    if (HR_RE.test(line)) {
      blocks.push({ kind: "hr" })
      i += 1
      continue
    }

    if (line.trimStart().startsWith(">")) {
      const quoted: string[] = []
      while (i < lines.length && (lines[i].trimStart().startsWith(">") || (quoted.length > 0 && lines[i].trim() !== ""))) {
        quoted.push(lines[i].trimStart().replace(/^>\s?/, ""))
        i += 1
      }
      blocks.push({ kind: "quote", blocks: parseBlocks(quoted) })
      continue
    }

    // Tables: a header row followed by a divider row.
    if (line.includes("|") && i + 1 < lines.length && TABLE_DIVIDER_RE.test(lines[i + 1]) && lines[i + 1].includes("-")) {
      const header = splitRow(line)
      const alignmentCells = splitRow(lines[i + 1])
      const alignment = header.map((_, index) => {
        const cell = alignmentCells[index] ?? ""
        const left = cell.startsWith(":")
        const right = cell.endsWith(":")
        return left && right ? "center" : right ? "right" : "left"
      })
      i += 2
      const rows: string[][] = []
      while (i < lines.length && lines[i].trim() !== "" && lines[i].includes("|")) {
        rows.push(splitRow(lines[i]))
        i += 1
      }
      blocks.push({ kind: "table", header, alignment, rows })
      continue
    }

    const ulMatch = line.match(UL_ITEM_RE)
    const olMatch = line.match(OL_ITEM_RE)
    if (ulMatch !== null || olMatch !== null) {
      const ordered = ulMatch === null
      const [items, next] = parseListItems(lines.slice(i), 0)
      blocks.push({ kind: "list", ordered, items })
      i += next
      continue
    }

    // Paragraph: consecutive plain lines.
    const paragraph: string[] = [line]
    i += 1
    while (i < lines.length && lines[i].trim() !== "") {
      const next = lines[i]
      if (
        HEADING_RE.test(next) ||
        HR_RE.test(next) ||
        FENCE_RE.test(next) ||
        next.trimStart().startsWith(">") ||
        UL_ITEM_RE.test(next) ||
        OL_ITEM_RE.test(next)
      ) {
        break
      }
      paragraph.push(next)
      i += 1
    }
    blocks.push({ kind: "paragraph", lines: paragraph })
  }

  return blocks
}

/**
 * Collects list items starting at `startIndex`, gathering continuation
 * lines and nested items by indentation. Returns the parsed items and
 * the number of source lines consumed.
 */
function parseListItems(lines: string[], indent: number): [ListItem[], number] {
  const items: ListItem[] = []
  let i = 0

  while (i < lines.length) {
    const line = lines[i]
    if (line.trim() === "") {
      // A blank line ends the list unless the next line keeps the indent.
      const next = lines[i + 1] ?? ""
      const nextIndent = next.match(/^\s*/)?.[0].length ?? 0
      if (next.trim() === "" || nextIndent <= indent) break
      i += 1
      continue
    }

    const ulMatch = line.match(UL_ITEM_RE)
    const olMatch = line.match(OL_ITEM_RE)
    const itemMatch = ulMatch ?? olMatch
    if (itemMatch === null) break

    const itemIndent = itemMatch[1].length
    if (itemIndent < indent) break
    if (itemIndent > indent + 3) break // not part of this level

    let content = itemMatch[2]
    let checked: boolean | null = null
    const task = content.match(TASK_RE)
    if (task !== null) {
      checked = task[1].toLowerCase() === "x"
      content = task[2]
    }

    const body: string[] = [content]
    i += 1

    // Continuation lines: deeper-indented non-item lines extend the body;
    // deeper-indented items start nested lists.
    const children: ListItem[][] = []
    let currentNested: ListItem[] | null = null
    while (i < lines.length) {
      const raw = lines[i]
      if (raw.trim() === "") {
        const next = lines[i + 1] ?? ""
        if (next.trim() === "" || (next.match(/^\s*/)?.[0].length ?? 0) <= itemIndent) break
        if (currentNested !== null) {
          // blank inside nested list, let the recursion decide
          const [nested, consumed] = continueNested(lines, i, itemIndent)
          if (nested.length > 0) {
            currentNested.push(...nested)
            i += consumed
            continue
          }
        }
        body.push("")
        i += 1
        continue
      }

      const lineIndent = raw.match(/^\s*/)?.[0].length ?? 0
      const nestedItem = raw.match(UL_ITEM_RE) ?? raw.match(OL_ITEM_RE)
      if (nestedItem !== null && lineIndent > itemIndent) {
        const [nested, consumed] = parseNested(lines, i, lineIndent)
        currentNested = nested
        children.push(nested)
        i += consumed
        continue
      }
      if (nestedItem !== null) break // back to this level, next iteration
      if (lineIndent > itemIndent) {
        body.push(raw.trim())
        i += 1
        continue
      }
      break // any same-indent non-item line ends the item
    }

    items.push({ lines: body, checked, children })
  }

  return [items, i]
}

function parseNested(lines: string[], start: number, indent: number): [ListItem[], number] {
  const [items, consumed] = parseListItems(lines.slice(start), indent)
  return [items, consumed]
}

function continueNested(lines: string[], start: number, indent: number): [ListItem[], number] {
  return parseListItems(lines.slice(start), indent + 1)
}

function renderBlocks(blocks: Block[], keyPrefix: string): React.ReactNode[] {
  return blocks.map((block, index) => {
    const key = `${keyPrefix}-${index}`
    switch (block.kind) {
      case "paragraph":
        return (
          <p key={key}>
            {block.lines.map((line, lineIndex) => (
              <React.Fragment key={`${key}-${lineIndex}`}>
                {lineIndex > 0 ? <br /> : null}
                {renderInline(line, `${key}-${lineIndex}`)}
              </React.Fragment>
            ))}
          </p>
        )
      case "heading": {
        const Tag = (["h1", "h2", "h3"] as const)[block.level - 1]
        return <Tag key={key}>{renderInline(block.text, key)}</Tag>
      }
      case "code":
        return (
          <pre key={key}>
            <code>{block.lines.join("\n")}</code>
          </pre>
        )
      case "quote":
        return <blockquote key={key}>{renderBlocks(block.blocks, key)}</blockquote>
      case "hr":
        return <hr key={key} />
      case "table":
        return (
          <table key={key}>
            <thead>
              <tr>
                {block.header.map((cell, cellIndex) => (
                  <th key={cellIndex} style={{ textAlign: block.alignment[cellIndex] }}>
                    {renderInline(cell, `${key}-h-${cellIndex}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {block.rows.map((row, rowIndex) => (
                <tr key={rowIndex}>
                  {row.map((cell, cellIndex) => (
                    <td key={cellIndex} style={{ textAlign: block.alignment[cellIndex] }}>
                      {renderInline(cell, `${key}-${rowIndex}-${cellIndex}`)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )
      case "list": {
        const isTaskList = block.items.some((item) => item.checked !== null)
        const Tag = block.ordered ? "ol" : "ul"
        if (isTaskList) {
          return (
            <ul key={key} data-type="taskList">
              {block.items.map((item, itemIndex) => renderListItem(item, `${key}-${itemIndex}`))}
            </ul>
          )
        }
        return (
          <Tag key={key}>
            {block.items.map((item, itemIndex) => renderListItem(item, `${key}-${itemIndex}`))}
          </Tag>
        )
      }
    }
  })
}

function renderListItem(item: ListItem, key: string): React.ReactNode {
  const body = (
    <>
      {item.lines.map((line, lineIndex) =>
        line.trim() === "" ? null : (
          <p key={`${key}-p-${lineIndex}`}>
            {lineIndex > 0 ? <br /> : null}
            {renderInline(line, `${key}-p-${lineIndex}`)}
          </p>
        ),
      )}
      {item.children.map((nested, nestedIndex) =>
        nested.length > 0 ? (
          <ul key={`${key}-n-${nestedIndex}`}>{nested.map((child, childIndex) => renderListItem(child, `${key}-n-${nestedIndex}-${childIndex}`))}</ul>
        ) : null,
      )}
    </>
  )

  if (item.checked === null) {
    return <li key={key}>{body}</li>
  }

  // Same DOM shape as the editor's task items, so the shared prose
  // selectors style both identically.
  return (
    <li key={key} data-type="taskItem">
      <label>
        <input type="checkbox" checked={item.checked} readOnly />
      </label>
      <div>{body}</div>
    </li>
  )
}

/**
 * Read-only markdown renderer: a small line-based parser producing React
 * elements — no ProseMirror instance, so a fiche with dozens of comments
 * renders plain DOM instead of dozens of editors. Covers the subset the
 * markdown editor serializes: headings, emphasis, inline code, links,
 * images, fenced code, quotes, lists (incl. task lists and one nesting
 * level), tables and rules.
 */
export function MarkdownView({ value, className }: { value: string; className?: string }) {
  const blocks = React.useMemo(() => parseBlocks(value.split("\n")), [value])

  return <div className={cn("text-sm", markdownProse, className)}>{renderBlocks(blocks, "md")}</div>
}
