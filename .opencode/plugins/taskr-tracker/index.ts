// taskr-tracker — reminds the agent to track its work in Taskr.
//
// The taskr-agent skill (`.opencode/skills/taskr-agent/`) defines the
// workflow: every code change belongs to a Taskr issue, and that issue must
// sit in an in_progress status while the work happens. This plugin supplies
// the feedback loop: it observes taskr_* tool calls to keep a lightweight
// in-memory picture of the current issue (the last created / moved /
// reparented issue, plus a statusId → category map learned from
// list_statuses), and after every edit/write on project source files it
// appends a reminder to the tool result when no issue is known to be in the
// in_progress category.
//
// Informational only: it never blocks an edit, never throws, and a reminder
// must never break the tool result it decorates.

const SOURCE_FILE = /(^|\/)(app|routes|database|config|resources\/js|resources\/views|\.opencode)\//

// MCP tool names arrive prefixed by the client (e.g. "taskr_move_issue"),
// so match on the tool suffix only.
const TASKR_TOOL = /(list_statuses|create_issue|move_issue|set_issue_parent|update_issue|set_labels|set_assignees)$/

type TrackedIssue = { id: string; title: string | null; parentId: string | null }

const state: {
  issue: TrackedIssue | null
  statusCategories: Record<string, string>
  issueCategory: string | null
  reminded: boolean
} = { issue: null, statusCategories: {}, issueCategory: null, reminded: false }

function structured(event: any): any {
  const r = event?.result ?? event
  return r?.structuredContent ?? r?.output?.structuredContent ?? r?.structured ?? null
}

function rememberStatuses(payload: any): void {
  const statuses = payload?.statuses
  if (!Array.isArray(statuses)) return
  for (const status of statuses) {
    if (status && typeof status.id === "string" && typeof status.category === "string") {
      state.statusCategories[status.id] = status.category
    }
  }
}

function rememberIssue(payload: any, adopt: boolean): void {
  const issue = payload?.issue
  if (!issue || typeof issue.id !== "string") return

  if (adopt || !state.issue || state.issue.id === issue.id) {
    if (!state.issue || state.issue.id !== issue.id) {
      state.issue = {
        id: issue.id,
        title: typeof issue.title === "string" ? issue.title : null,
        parentId: typeof issue.parentId === "string" ? issue.parentId : null,
      }
      state.reminded = false
    } else {
      if (typeof issue.title === "string") state.issue.title = issue.title
      if (typeof issue.parentId === "string") state.issue.parentId = issue.parentId
    }
    if (typeof issue.statusId === "string") {
      state.issueCategory = state.statusCategories[issue.statusId] ?? state.issueCategory
    }
  }

  if (state.issue && state.issue.id === issue.id && (state.issueCategory === "done" || state.issueCategory === "cancelled")) {
    // The tracked work is over: forget it so the next source edit reminds again.
    state.issue = null
    state.issueCategory = null
    state.reminded = false
  }
}

function onTaskrTool(tool: string, payload: any): void {
  // Any Taskr interaction re-arms the reminder for the next source edit.
  state.reminded = false
  if (/list_statuses$/.test(tool)) {
    rememberStatuses(payload)
  } else if (/create_issue$/.test(tool)) {
    rememberIssue(payload, true)
  } else if (/(move_issue|set_issue_parent)$/.test(tool)) {
    rememberIssue(payload, true)
  } else if (/(update_issue|set_labels|set_assignees)$/.test(tool)) {
    rememberIssue(payload, false)
  }
}

function maybeRemind(event: any): void {
  if (state.reminded) return
  if (state.issue && state.issueCategory === "in_progress") return

  state.reminded = true
  const lines: string[] = []
  if (state.issue) {
    lines.push(`⚠️ Tracked issue "${state.issue.title ?? state.issue.id}" (${state.issue.id}) is not in an in_progress status.`)
    lines.push("Per the taskr-agent skill: move_issue it to a status of category in_progress before writing more code.")
  } else {
    lines.push("⚠️ You are editing project code with no Taskr issue tracked as in_progress.")
    lines.push(
      "Per the taskr-agent skill: create the issue (create_issue; link children with set_issue_parent) or pick one up (list_issues, category=in_progress), then move_issue it to an in_progress status before coding.",
    )
  }
  lines.push("This reminder is informational — it never blocks the edit.")
  inject(event, block(lines))
}

function inject(target: unknown, text: string): void {
  try {
    if (!target || typeof target !== "object") return
    const t = target as Record<string, any>

    // OpenCode v2: the event carries a mutable result with content parts.
    const r = t.result
    if (r && typeof r === "object") {
      if (typeof r.content === "string") r.content = `${r.content}\n${text}`
      else if (Array.isArray(r.content)) r.content.push({ type: "text", text })
      else if (typeof r.text === "string") r.text = `${r.text}\n${text}`
      if (r.metadata && typeof r.metadata === "object") r.metadata.taskrTracker = text
    }

    // OpenCode v1: output is { title, output, metadata } with a string output.
    if (typeof t.output === "string") t.output = `${t.output}\n${text}`
    else if (t.output && typeof t.output === "object" && typeof t.output.output === "string") {
      t.output.output = `${t.output.output}\n${text}`
    }
  } catch {
    // A reminder must never break the tool result it decorates.
  }
}

function block(lines: string[]): string {
  return ["── taskr-tracker ──", ...lines, "────────────────"].join("\n")
}

async function guard(tool: string, args: Record<string, unknown> | undefined, event: any): Promise<void> {
  try {
    if (tool === "edit" || tool === "write") {
      const raw = args?.path ?? args?.filePath ?? args?.file_path
      if (typeof raw !== "string" || raw === "") return
      if (!SOURCE_FILE.test(raw.replace(/\\/g, "/"))) return
      maybeRemind(event)
      return
    }
    if (TASKR_TOOL.test(tool)) {
      onTaskrTool(tool, structured(event))
    }
  } catch {
    // A reminder must never break the tool result it decorates.
  }
}

// OpenCode v1 entrypoint: server(ctx) -> hooks.
export const TaskrTrackerPlugin = async (): Promise<
  Record<string, (input: any, output: any) => Promise<void>>
> => ({
  "tool.execute.after": async (input, output) => {
    await guard(String(input?.tool ?? ""), input?.args, { output })
  },
})

// OpenCode v2 entrypoint.
const setup = async (ctx: any) => {
  await ctx.tool.hook("execute.after", async (event: any) => {
    await guard(String(event?.tool ?? ""), event?.input ?? event?.args ?? {}, event)
  })
}

export default { id: "taskr-tracker", server: TaskrTrackerPlugin, setup }
