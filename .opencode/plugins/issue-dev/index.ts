// issue-dev — lifecycle enforcement for TaskR issue development.
//
// Mirrors the Vibe hook .vibe/hooks/issue-dev.php as an OpenCode plugin:
//
// 1. tool.execute.before: blocks out-of-order actions while a workflow is
//    active — source edits before the plan comment, unmarked TaskR comments,
//    and time entries before the done comment.
//
// 2. tool.execute.after: records progress from the observed tool calls
//    (knowledge search, code exploration, plan/done comments, time entries,
//    knowledge updates) and nudges the agent to declare the workflow when it
//    touches the TaskR connector with no active workflow.
//
// 3. session.idle: refuses to end a turn while a declared workflow is
//    incomplete (research, plan comment, done comment, time entry, knowledge
//    review).
//
// 4. issue_dev tool: the state machine the agent drives — start, status,
//    knowledge-reviewed, cancel.
//
// State lives in one JSON file per project root. One active workflow per
// repo at a time: concurrent sessions on the same checkout share it.
//
// The taskr-issue-dev skill defines the workflow; this plugin makes each
// step impossible to skip, mirroring the tdd-guard/tdd-gate pattern.

import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs"
import { homedir } from "node:os"
import path from "node:path"
import crypto from "node:crypto"

const PLAN_MARKER = "taskr:plan"
const DONE_MARKER = "taskr:done"

// Editing these paths counts as implementation and requires the plan comment.
// .opencode/, docs and scratch files are excluded on purpose.
const SOURCE_FILE = /(^|\/)(app|routes|database|config|resources\/js|resources\/views|tests)\//

// ---------------------------------------------------------------------------
// State management (one JSON file per project root)
// ---------------------------------------------------------------------------

type IssueDevState = {
  active: boolean
  workspace_id?: string
  issue_id?: string
  started_at?: number
  knowledge_searched: boolean
  code_explored: boolean
  plan_comment: boolean
  done_comment: boolean
  time_logged: boolean
  knowledge_reviewed: boolean
  completed_at?: number
}

function projectRoot(): string {
  // Walk up from cwd to find the Laravel project root.
  let dir = process.cwd()
  for (let level = 0; level < 10; level += 1) {
    if (existsSync(path.join(dir, "artisan")) || existsSync(path.join(dir, "composer.json"))) return dir
    const parent = path.dirname(dir)
    if (parent === dir) break
    dir = parent
  }
  return process.cwd()
}

function statePath(): string {
  const root = projectRoot()
  const hash = crypto.createHash("sha1").update(root).digest("hex").slice(0, 12)
  return path.join(homedir(), ".cache", "opencode-issue-dev", `${hash}.json`)
}

function loadState(): IssueDevState {
  try {
    const raw = readFileSync(statePath(), "utf-8")
    const parsed = JSON.parse(raw)
    return {
      active: parsed.active ?? false,
      workspace_id: parsed.workspace_id,
      issue_id: parsed.issue_id,
      started_at: parsed.started_at,
      knowledge_searched: parsed.knowledge_searched ?? false,
      code_explored: parsed.code_explored ?? false,
      plan_comment: parsed.plan_comment ?? false,
      done_comment: parsed.done_comment ?? false,
      time_logged: parsed.time_logged ?? false,
      knowledge_reviewed: parsed.knowledge_reviewed ?? false,
      completed_at: parsed.completed_at,
    }
  } catch {
    return {
      active: false,
      knowledge_searched: false,
      code_explored: false,
      plan_comment: false,
      done_comment: false,
      time_logged: false,
      knowledge_reviewed: false,
    }
  }
}

function saveState(state: IssueDevState): void {
  try {
    mkdirSync(path.dirname(statePath()), { recursive: true })
    writeFileSync(statePath(), JSON.stringify(state, null, 2))
  } catch {
    // State persistence must never break the tool flow.
  }
}

function freshState(workspaceId: string, issueId: string): IssueDevState {
  return {
    active: true,
    workspace_id: workspaceId,
    issue_id: issueId,
    started_at: Date.now(),
    knowledge_searched: false,
    code_explored: false,
    plan_comment: false,
    done_comment: false,
    time_logged: false,
    knowledge_reviewed: false,
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function issueLabel(state: IssueDevState): string {
  return state.issue_id ?? "unknown"
}

function missingSteps(state: IssueDevState): string[] {
  const steps: string[] = []
  if (!state.knowledge_searched) {
    steps.push("search the knowledge base (search_knowledge + get_knowledge on the relevant hits)")
  }
  if (!state.code_explored) {
    steps.push("explore the codebase (read files / grep the areas concerned)")
  }
  if (!state.plan_comment) {
    steps.push(`post the plan comment (add_comment starting with \`<!-- ${PLAN_MARKER} -->\`)`)
  }
  if (!state.done_comment) {
    steps.push(`post the done comment (add_comment starting with \`<!-- ${DONE_MARKER} -->\`)`)
  }
  if (!state.time_logged) {
    steps.push("log the time spent (first comment created_at -> now, then log_time)")
  }
  if (!state.knowledge_reviewed) {
    steps.push(
      `reconcile the knowledge base (update_knowledge / create_knowledge, or issue_dev action="knowledge-reviewed" when nothing deserves an update)`,
    )
  }
  return steps
}

function inputPath(input: Record<string, unknown>): string | null {
  for (const key of ["file_path", "path", "filePath", "target_path"]) {
    const value = input[key]
    if (typeof value === "string" && value !== "") {
      return value.replace(/\\/g, "/")
    }
  }
  return null
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
      if (r.metadata && typeof r.metadata === "object") r.metadata.issueDev = text
    }

    // OpenCode v1: output is { title, output, metadata } with a string output.
    if (typeof t.output === "string") t.output = `${t.output}\n${text}`
    else if (t.output && typeof t.output === "object" && typeof t.output.output === "string") {
      t.output.output = `${t.output.output}\n${text}`
    }
  } catch {
    // A guard must never break the tool result it decorates.
  }
}

function block(lines: string[]): string {
  return ["── issue-dev ──", ...lines, "──────────────"].join("\n")
}

// MCP tool names arrive prefixed by the client (e.g. "taskr_search_knowledge"),
// so match on the tool suffix only.
const TASKR_TOOL = /(list_workspaces|list_projects|list_statuses|list_labels|list_issues|create_issue|set_issue_parent|move_issue|update_issue|set_labels|set_assignees|add_comment|list_comments|log_time|list_time_entries|search_knowledge|get_knowledge|update_knowledge|create_knowledge)$/

function isTaskrTool(tool: string): boolean {
  return /taskr[_-]/.test(tool) || TASKR_TOOL.test(tool)
}

// ---------------------------------------------------------------------------
// CLI / tool mode — the state machine the agent drives
// ---------------------------------------------------------------------------

type CliResult = { ok: boolean; message: string }

function cliMain(args: {
  action: string
  workspaceId?: string
  issueId?: string
}): CliResult {
  const state = loadState()

  switch (args.action) {
    case "start": {
      const workspaceId = (args.workspaceId ?? "").trim()
      const issueId = (args.issueId ?? "").trim()
      if (!/^[0-9A-Za-z]{20,}$/.test(workspaceId) || !/^[0-9A-Za-z]{20,}$/.test(issueId)) {
        return {
          ok: false,
          message: `usage: issue_dev action="start" workspaceId="<workspace_ulid>" issueId="<issue_ulid>"`,
        }
      }
      saveState(freshState(workspaceId, issueId))
      return {
        ok: true,
        message:
          `issue-dev: workflow opened for issue ${issueId}.\n` +
          `Next: research the issue (search_knowledge + get_knowledge + code exploration), then the plan comment with \`<!-- ${PLAN_MARKER} -->\`.`,
      }
    }

    case "status": {
      if (!state.active) {
        return { ok: true, message: "issue-dev: no active workflow." }
      }
      const started = state.started_at ? new Date(state.started_at).toISOString().slice(0, 16).replace("T", " ") : "unknown"
      const steps = missingSteps(state)
      return {
        ok: true,
        message:
          `issue-dev: workflow active for issue ${issueLabel(state)} (started ${started}).\n` +
          (steps.length === 0
            ? "All steps complete — the gate will close the workflow."
            : `Remaining:\n- ${steps.join("\n- ")}`),
      }
    }

    case "knowledge-reviewed": {
      if (!state.active) {
        return { ok: false, message: "issue-dev: no active workflow to mark." }
      }
      state.knowledge_reviewed = true
      saveState(state)
      const steps = missingSteps(state)
      return {
        ok: true,
        message:
          "issue-dev: knowledge review attested.\n" +
          (steps.length === 0 ? "All steps complete." : `Remaining:\n- ${steps.join("\n- ")}`),
      }
    }

    case "cancel": {
      saveState({ ...state, active: false })
      return { ok: true, message: "issue-dev: workflow cancelled — the gate stands down." }
    }

    default:
      return {
        ok: false,
        message: `usage: issue_dev action="start" workspaceId="..." issueId="..." | action="status" | action="knowledge-reviewed" | action="cancel"`,
      }
  }
}

// ---------------------------------------------------------------------------
// Pre-tool ordering rules (mirrors preToolChecks in issue-dev.php)
// ---------------------------------------------------------------------------

function preToolChecks(state: IssueDevState, tool: string, input: Record<string, unknown>): void {
  // Allow the issue_dev tool itself.
  if (tool === "issue_dev" || tool.endsWith("_issue_dev")) return

  const path = inputPath(input)

  // Source edits require the plan comment.
  if ((tool.includes("edit") || tool.includes("write")) && path !== null && SOURCE_FILE.test(path)) {
    if (!state.plan_comment) {
      throw new Error(
        `issue-dev: the plan comment for issue ${issueLabel(state)} must be posted before touching code. ` +
          `Research the issue first (search_knowledge + get_knowledge + code exploration), then add_comment starting with \`<!-- ${PLAN_MARKER} -->\` (see the taskr-issue-dev skill).`,
      )
    }
    return
  }

  // TaskR connector tool calls (MCP tools arrive with taskr_ prefix or as execute tool with code).
  const isExecuteTool = tool.includes("execute") || tool.includes("typescript") || tool.includes("codemode")
  const code = String(input.code ?? input.command ?? "")

  if (isTaskrTool(tool) || (isExecuteTool && code.includes("connector_taskr"))) {
    // log_time before done comment.
    if ((tool.includes("log_time") || code.includes("log_time")) && !state.done_comment) {
      throw new Error(
        "issue-dev: log_time comes after the done comment — the logged duration runs from the issue's first comment to now, so the implementation must be finished and reported first.",
      )
    }

    // add_comment marker enforcement.
    const isAddComment = tool.includes("add_comment") || code.includes("add_comment")
    if (isAddComment) {
      const hasPlan = code.includes(PLAN_MARKER) || String(input.body ?? "").includes(`<!-- ${PLAN_MARKER} -->`)
      const hasDone = code.includes(DONE_MARKER) || String(input.body ?? "").includes(`<!-- ${DONE_MARKER} -->`)

      if (!hasPlan && !hasDone) {
        throw new Error(
          `issue-dev: comments on issue ${issueLabel(state)} must start with a marker line — \`<!-- ${PLAN_MARKER} -->\` for the pre-implementation plan, \`<!-- ${DONE_MARKER} -->\` for the completion report.`,
        )
      }
      if (hasDone && !state.plan_comment) {
        throw new Error("issue-dev: the plan comment comes first — post the plan (`<!-- taskr:plan -->`) before the done comment.")
      }
      if (hasDone && state.done_comment) {
        throw new Error(
          `issue-dev: the done comment for issue ${issueLabel(state)} was already posted — amend it with update_comment instead of adding a new one.`,
        )
      }
      if (hasPlan && state.plan_comment) {
        throw new Error(
          `issue-dev: the plan comment for issue ${issueLabel(state)} was already posted — the next marked comment is the done report (\`<!-- ${DONE_MARKER} -->\`) once the implementation is finished.`,
        )
      }
      if (hasPlan) {
        const blockers: string[] = []
        if (!state.knowledge_searched) blockers.push("search the knowledge base first (search_knowledge + get_knowledge)")
        if (!state.code_explored) blockers.push("explore the codebase first (read files / grep)")
        if (blockers.length > 0) {
          throw new Error(`issue-dev: research before planning — ${blockers.join("; ")}.`)
        }
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Post-tool progress recording (mirrors recordProgress in issue-dev.php)
// ---------------------------------------------------------------------------

function recordProgress(state: IssueDevState, tool: string, event: any): boolean {
  const input = (event?.input ?? event?.args ?? {}) as Record<string, unknown>
  if (event?.error === true || event?.result?.metadata?.error === true) return false

  let changed = false
  const code = String(input.code ?? "")

  // TaskR connector tools (MCP tools arrive with taskr_ prefix).
  if (isTaskrTool(tool)) {
    if (tool.includes("search_knowledge") && !state.knowledge_searched) {
      state.knowledge_searched = true
      changed = true
    }
    if ((tool.includes("update_knowledge") || tool.includes("create_knowledge")) && !state.knowledge_reviewed) {
      state.knowledge_reviewed = true
      changed = true
    }
    if (tool.includes("add_comment")) {
      const body = String(input.body ?? "")
      if (body.includes(`<!-- ${PLAN_MARKER} -->`) && !state.plan_comment) {
        state.plan_comment = true
        changed = true
      }
      if (body.includes(`<!-- ${DONE_MARKER} -->`) && state.plan_comment && !state.done_comment) {
        state.done_comment = true
        changed = true
      }
    }
    if (tool.includes("log_time") && state.done_comment && !state.time_logged) {
      state.time_logged = true
      changed = true
    }
  }

  // Code Mode execute tool: inspect the code for TaskR connector calls.
  if ((tool.includes("execute") || tool.includes("typescript") || tool.includes("codemode")) && code.includes("connector_taskr")) {
    if (code.includes("search_knowledge") && !state.knowledge_searched) {
      state.knowledge_searched = true
      changed = true
    }
    if ((code.includes("update_knowledge") || code.includes("create_knowledge")) && !state.knowledge_reviewed) {
      state.knowledge_reviewed = true
      changed = true
    }
    if (code.includes("add_comment")) {
      if (code.includes(PLAN_MARKER) && !state.plan_comment) {
        state.plan_comment = true
        changed = true
      }
      if (code.includes(DONE_MARKER) && state.plan_comment && !state.done_comment) {
        state.done_comment = true
        changed = true
      }
    }
    if (code.includes("log_time") && state.done_comment && !state.time_logged) {
      state.time_logged = true
      changed = true
    }
  }

  // Bash commands that explore code.
  if (tool.includes("bash") && !tool.includes("issue_dev")) {
    const command = String(input.command ?? "")
    if (/\b(grep|rg|find|artisan)\b/.test(command) && !state.code_explored) {
      state.code_explored = true
      changed = true
    }
  }

  // Read tool on source files.
  if (tool.includes("read")) {
    const path = inputPath(input)
    if (path !== null && SOURCE_FILE.test(path) && !state.code_explored) {
      state.code_explored = true
      changed = true
    }
  }

  return changed
}

// ---------------------------------------------------------------------------
// Turn gate (mirrors the post_agent branch in issue-dev.php)
// ---------------------------------------------------------------------------

async function turnGate(ctx: any, sessionId: string): Promise<void> {
  const state = loadState()
  if (!state.active) return

  const steps = missingSteps(state)
  if (steps.length === 0) {
    state.active = false
    state.completed_at = Date.now()
    saveState(state)
    return
  }

  await ctx.client.session.prompt({
    sessionID: sessionId,
    text:
      `issue-dev-gate: the TaskR issue ${issueLabel(state)} workflow is incomplete:\n` +
      `- ${steps.join("\n- ")}\n` +
      `Finish them (see the taskr-issue-dev skill), or run issue_dev action="cancel" if this is not a TaskR issue workflow.`,
  })
}

// ---------------------------------------------------------------------------
// Plugin entrypoints
// ---------------------------------------------------------------------------

// OpenCode v1 entrypoint: server(ctx) -> hooks.
export const IssueDevPlugin = async (ctx: any): Promise<
  Record<string, (input: any, output: any) => Promise<void>>
> => ({
  "tool.execute.before": async (input, output) => {
    const state = loadState()
    if (!state.active) return
    const tool = String(input?.tool ?? "")
    preToolChecks(state, tool, input?.args ?? output?.args ?? {})
  },
  "tool.execute.after": async (input, output) => {
    const tool = String(input?.tool ?? "")
    const state = loadState()

    if (state.active) {
      if (recordProgress(state, tool, { input: input?.args, ...output })) {
        saveState(state)
      }
      return
    }

    // Not in a workflow, yet the TaskR connector is being used: remind the
    // agent the lifecycle exists before it silently bypasses it.
    const args = input?.args ?? {}
    const code = String(args.code ?? "")
    if ((isTaskrTool(tool) || code.includes("connector_taskr")) && !tool.includes("issue_dev")) {
      inject(output, block([
        "The TaskR connector is in use with no declared issue workflow.",
        `If this task is a TaskR issue: run issue_dev action="start" workspaceId="<workspace_ulid>" issueId="<issue_ulid>" and follow the taskr-issue-dev skill (research, plan comment, implement, done comment, time entry, knowledge review).`,
        "If it is not (triage, admin, read-only lookup): ignore this reminder.",
      ]))
    }
  },
  event: async (event) => {
    if (event.type === "session.idle") {
      const sessionId = String(event.sessionID ?? event.session_id ?? "default")
      await turnGate(ctx, sessionId)
    }
  },
})

// OpenCode v2 entrypoint.
const setup = async (ctx: any) => {
  // Register the issue_dev tool for the state machine.
  await ctx.tool.transform((editor: any) => {
    editor.add({
      name: "issue_dev",
      description:
        "Manage the TaskR issue development workflow state machine. Actions: start (requires workspaceId + issueId), status, knowledge-reviewed, cancel.",
      input: {
        type: "object",
        properties: {
          action: {
            type: "string",
            enum: ["start", "status", "knowledge-reviewed", "cancel"],
            description: "The workflow action to perform.",
          },
          workspaceId: {
            type: "string",
            description: "The workspace ULID (required for action=start).",
          },
          issueId: {
            type: "string",
            description: "The issue ULID (required for action=start).",
          },
        },
        required: ["action"],
        additionalProperties: false,
      },
      execute: async (input: { action: string; workspaceId?: string; issueId?: string }) => {
        const result = cliMain(input)
        return {
          content: result.message,
          metadata: { ok: result.ok },
        }
      },
    })
  })

  // Pre-tool: block out-of-order actions.
  await ctx.tool.hook("execute.before", async (event: any) => {
    const state = loadState()
    if (!state.active) return
    const tool = String(event?.tool ?? "")
    // Allow the issue_dev tool itself.
    if (tool === "issue_dev" || tool.endsWith("_issue_dev")) return
    preToolChecks(state, tool, event?.input ?? event?.args ?? {})
  })

  // Post-tool: record progress, nudge when TaskR is used untracked.
  await ctx.tool.hook("execute.after", async (event: any) => {
    const tool = String(event?.tool ?? "")
    const state = loadState()

    if (state.active) {
      if (recordProgress(state, tool, event)) {
        saveState(state)
      }
      return
    }

    // Not in a workflow, yet the TaskR connector is being used.
    const input = event?.input ?? event?.args ?? {}
    const code = String((input as any).code ?? "")
    if ((isTaskrTool(tool) || code.includes("connector_taskr")) && !tool.includes("issue_dev")) {
      inject(event, block([
        "The TaskR connector is in use with no declared issue workflow.",
        `If this task is a TaskR issue: run issue_dev action="start" workspaceId="<workspace_ulid>" issueId="<issue_ulid>" and follow the taskr-issue-dev skill (research, plan comment, implement, done comment, time entry, knowledge review).`,
        "If it is not (triage, admin, read-only lookup): ignore this reminder.",
      ]))
    }
  })

  // Turn gate: refuse to end the turn while a workflow is incomplete.
  const controller = new AbortController()
  void (async () => {
    try {
      for await (const event of ctx.event.subscribe({ signal: controller.signal })) {
        if (event.type === "session.idle") {
          const sessionId = String((event as any).sessionID ?? (event as any).session_id ?? "default")
          await turnGate(ctx, sessionId)
        }
      }
    } catch {
      // Stream aborted on unload.
    }
  })()
  return () => controller.abort()
}

export default { id: "issue-dev", server: IssueDevPlugin, setup }
