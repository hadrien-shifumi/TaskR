// issue-dev — slim lifecycle enforcement for TaskR issue development.
//
// 1. tool.execute.before: blocks out-of-order actions while a workflow is
//    active — source edits before the plan comment, log_time before the
//    done comment, unsigned comments on the workflow issue, and plan/done
//    markers posted out of order or twice.
//
// 2. tool.execute.after: records progress from the observed tool calls
//    (plan/done comments, time entries, knowledge updates).
//
// 3. session.idle: refuses to end the owner's turn while a declared
//    workflow is incomplete (plan comment, done comment, time entry,
//    knowledge review).
//
// 4. issue_dev tool: the state machine the orchestrator drives — start,
//    status, knowledge-reviewed, cancel.
//
// 5. TaskR statuses: start -> In Progress, closure -> Done. Best effort
//    over the TaskR MCP server (./taskr.ts): an outage skips the sync,
//    it never breaks the workflow.
//
// TaskR stays the single source of truth for the workflow: the plan, the
// done report and the scout's functional summary live as signed comments
// on the issue — readable by non-tech — while the deep technical analysis
// stays in the opencode sessions. State lives in one JSON file per
// project root — one active workflow per repo at a time, shared by every
// concurrent session on the same checkout.
//
// The taskr-issue-dev skill defines the workflow; this plugin makes each
// step impossible to skip.

import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs"
import { homedir } from "node:os"
import path from "node:path"
import crypto from "node:crypto"
import { createTaskrClient, type TaskrStatus } from "./taskr"

const PLAN_MARKER = "taskr:plan"
const DONE_MARKER = "taskr:done"

// Editing these paths counts as implementation and requires the plan comment.
const SOURCE_FILE = /(^|\/)(app|routes|database|config|resources\/js|resources\/views|tests)\//

// ---------------------------------------------------------------------------
// State management (one JSON file per project root)
// ---------------------------------------------------------------------------

export type IssueDevState = {
  active: boolean
  workspace_id?: string
  issue_id?: string
  started_at?: number
  session_id?: string
  agent?: string
  plan_comment: boolean
  done_comment: boolean
  time_logged: boolean
  knowledge_reviewed: boolean
  completed_at?: number
}

// The plugin runtime's working directory is the OpenCode server's cwd (often
// the home directory), not the project. OpenCode exposes the real project
// directory on ctx.location.directory; capture it at load time so the state
// file stays scoped to the project instead of being shared across every
// repository and every session.
let locationDirectory: string | null = null

export function setLocation(directory: unknown): void {
  if (typeof directory === "string" && directory !== "") {
    locationDirectory = directory
  }
}

function projectRoot(): string {
  const start = locationDirectory ?? process.cwd()
  let dir = start
  for (let level = 0; level < 10; level += 1) {
    if (existsSync(path.join(dir, "artisan")) || existsSync(path.join(dir, "composer.json"))) return dir
    const parent = path.dirname(dir)
    if (parent === dir) break
    dir = parent
  }
  return start
}

export function statePath(): string {
  const root = projectRoot()
  const hash = crypto.createHash("sha1").update(root).digest("hex").slice(0, 12)
  return path.join(homedir(), ".cache", "opencode-issue-dev", `${hash}.json`)
}

export function loadState(): IssueDevState {
  try {
    const raw = readFileSync(statePath(), "utf-8")
    const parsed = JSON.parse(raw)
    return {
      active: parsed.active ?? false,
      workspace_id: parsed.workspace_id,
      issue_id: parsed.issue_id,
      started_at: parsed.started_at,
      session_id: parsed.session_id,
      agent: parsed.agent,
      plan_comment: parsed.plan_comment ?? false,
      done_comment: parsed.done_comment ?? false,
      time_logged: parsed.time_logged ?? false,
      knowledge_reviewed: parsed.knowledge_reviewed ?? false,
      completed_at: parsed.completed_at,
    }
  } catch {
    return {
      active: false,
      plan_comment: false,
      done_comment: false,
      time_logged: false,
      knowledge_reviewed: false,
    }
  }
}

export function saveState(state: IssueDevState): void {
  try {
    mkdirSync(path.dirname(statePath()), { recursive: true })
    writeFileSync(statePath(), JSON.stringify(state, null, 2))
  } catch {
    // State persistence must never break the tool flow.
  }
}

export function freshState(workspaceId: string, issueId: string, sessionId?: string, agent?: string): IssueDevState {
  return {
    active: true,
    workspace_id: workspaceId,
    issue_id: issueId,
    started_at: Date.now(),
    session_id: sessionId,
    agent,
    plan_comment: false,
    done_comment: false,
    time_logged: false,
    knowledge_reviewed: false,
  }
}

// ---------------------------------------------------------------------------
// TaskR status sync (start -> In Progress, closure -> Done)
// ---------------------------------------------------------------------------

function statusByCategory(statuses: TaskrStatus[], category: string): TaskrStatus | null {
  for (const status of statuses) {
    if (status.category === category) return status
  }
  return null
}

export type StatusSync = { reachable: boolean; notes: string[] }

// A started issue is In Progress. Best effort — an unreachable TaskR never
// fails the start.
export async function syncStartStatuses(workspaceId: string, issueId: string, ctx?: any): Promise<StatusSync> {
  const client = await createTaskrClient(ctx)
  if (client === null) return { reachable: false, notes: [] }

  const issue = await client.getIssue(workspaceId, issueId)
  const statuses = issue !== null ? await client.listStatuses(workspaceId) : null
  if (issue === null || statuses === null) return { reachable: false, notes: [] }

  if (issue.status?.category === "in_progress") return { reachable: true, notes: [] }

  const inProgress = statusByCategory(statuses, "in_progress")
  if (inProgress === null) return { reachable: true, notes: [] }

  const moved = await client.moveIssue(workspaceId, issueId, inProgress.id)
  if (moved !== null) return { reachable: true, notes: [`issue ${issueId} moved to ${inProgress.name} (was ${issue.status?.name})`] }
  return { reachable: true, notes: [] }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function issueLabel(state: IssueDevState): string {
  return state.issue_id ?? "unknown"
}

function missingSteps(state: IssueDevState): string[] {
  const steps: string[] = []
  if (!state.plan_comment) {
    steps.push(`post the plan comment (add_comment containing \`<!-- ${PLAN_MARKER} -->\`, signed)`)
  }
  if (!state.done_comment) {
    steps.push(`post the done comment (add_comment containing \`<!-- ${DONE_MARKER} -->\`, signed)`)
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

function firstString(input: Record<string, unknown>, keys: string[]): string | null {
  for (const key of keys) {
    const value = input[key]
    if (typeof value === "string" && value !== "") return value
  }
  return null
}

function targetIssueId(input: Record<string, unknown>): string | null {
  return firstString(input, ["issue_id", "issueId"])
}

// A TaskR write only belongs to the workflow when it targets the workflow's
// issue. Comments and time entries on any other issue are ordinary tracker
// work: they must never be blocked by the workflow, nor recorded against it.
function targetsWorkflow(state: IssueDevState, input: Record<string, unknown>): boolean {
  const target = targetIssueId(input)
  return target === null || target === state.issue_id
}

// An agent signature — [scout], [dev], [expert], [orchestrator] — anywhere
// in the body, case-insensitive. The bracketed form counts with or without
// bold markers (**[Scout]** contains [scout]), and so does the "name:" form.
const AGENT_NAMES = ["scout", "dev", "expert", "orchestrator"]

function hasAgentSignature(body: string): boolean {
  const text = body.toLowerCase()
  return AGENT_NAMES.some((name) => text.includes(`[${name}]`) || new RegExp(`\\b${name}\\s*:`).test(text))
}

// MCP tool names arrive prefixed by the client (e.g. "taskr_search_knowledge"),
// so match on the tool suffix only.
const TASKR_TOOL = /(list_workspaces|list_projects|list_statuses|list_labels|list_issues|create_issue|set_issue_parent|move_issue|update_issue|set_labels|set_assignees|add_comment|list_comments|log_time|list_time_entries|search_knowledge|get_knowledge|update_knowledge|create_knowledge)$/

function isTaskrTool(tool: string): boolean {
  return /taskr[_-]/.test(tool) || TASKR_TOOL.test(tool)
}

// ---------------------------------------------------------------------------
// CLI / tool mode — the state machine the orchestrator drives
// ---------------------------------------------------------------------------

type CliResult = { ok: boolean; message: string }

export async function cliMain(args: {
  action: string
  workspaceId?: string
  issueId?: string
  sessionId?: string
  agent?: string
}, ctx?: any): Promise<CliResult> {
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
      const started = freshState(workspaceId, issueId, args.sessionId, args.agent)
      saveState(started)

      const sync = await syncStartStatuses(workspaceId, issueId, ctx)
      const statusLine = !sync.reachable
        ? "TaskR statuses: server unreachable — statuses left untouched."
        : sync.notes.length > 0
          ? `TaskR statuses: ${sync.notes.join("; ")}.`
          : `TaskR statuses: issue ${issueId} already in progress.`

      return {
        ok: true,
        message:
          `issue-dev: workflow opened for issue ${issueId}.\n` +
          `${statusLine}\n` +
          `Next: dispatch the scout for research, then the dev posts the plan comment with \`<!-- ${PLAN_MARKER} -->\` before any code.`,
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
// Pre-tool ordering rules
// ---------------------------------------------------------------------------

export async function preToolChecks(
  state: IssueDevState,
  tool: string,
  input: Record<string, unknown>,
): Promise<void> {
  // Allow the issue_dev tool itself.
  if (tool === "issue_dev" || tool.endsWith("_issue_dev")) return

  const path = inputPath(input)

  // Source edits require the plan comment.
  if ((tool.includes("edit") || tool.includes("write")) && path !== null && SOURCE_FILE.test(path)) {
    if (!state.plan_comment) {
      throw new Error(
        `issue-dev: the plan comment for issue ${issueLabel(state)} must be posted before touching code. ` +
          `Post add_comment containing \`<!-- ${PLAN_MARKER} -->\` first (see the taskr-issue-dev skill).`,
      )
    }
    return
  }

  // Code Mode wraps MCP calls in `execute`, but the inner tool fires its own
  // execute.before event with the real name and arguments.
  if (tool.includes("execute") || tool.includes("typescript") || tool.includes("codemode")) return

  if (isTaskrTool(tool) && targetsWorkflow(state, input)) {
    // log_time before done comment.
    if (tool.includes("log_time") && !state.done_comment) {
      throw new Error(
        "issue-dev: log_time comes after the done comment — the logged duration runs from the issue's first comment to now, so the implementation must be finished and reported first.",
      )
    }

    // Comment gate: a comment on the workflow issue is refused only when it
    // carries no agent signature. The plan/done markers remain what advances
    // the workflow state; a signed comment without a marker is ordinary
    // tracker work (research summary, notes, questions) and never changes
    // the state.
    if (tool.includes("add_comment")) {
      const body = String(input.body ?? "")

      if (!hasAgentSignature(body)) {
        throw new Error(
          `issue-dev: comments on issue ${issueLabel(state)} need an agent signature — [scout], [dev], [expert] or [orchestrator], anywhere in the body.`,
        )
      }

      const hasPlan = body.includes(`<!-- ${PLAN_MARKER} -->`)
      const hasDone = body.includes(`<!-- ${DONE_MARKER} -->`)

      if (hasDone && !state.plan_comment) {
        throw new Error(
          "issue-dev: plan first — post the plan comment (`<!-- taskr:plan -->`) before the done report.",
        )
      }
      if (hasDone && state.done_comment) {
        throw new Error(
          `issue-dev: the done comment on issue ${issueLabel(state)} is already posted — amend it with update_comment instead of adding a new one.`,
        )
      }
      if (hasPlan && state.plan_comment) {
        throw new Error(
          `issue-dev: the plan comment on issue ${issueLabel(state)} is already posted — the next marked comment is the done report (\`<!-- taskr:done -->\`); post without a marker for plain signed notes.`,
        )
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Post-tool progress recording
// ---------------------------------------------------------------------------

export function recordProgress(state: IssueDevState, tool: string, event: any): boolean {
  const input = (event?.input ?? event?.args ?? {}) as Record<string, unknown>
  // V2 reports failures through status; V1 used an error flag. Honour both.
  if (event?.status === "error" || event?.error === true || event?.result?.metadata?.error === true) return false

  if (!isTaskrTool(tool)) return false

  let changed = false
  const isWorkflowIssue = targetsWorkflow(state, input)

  if (isWorkflowIssue && tool.includes("add_comment")) {
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
  if (isWorkflowIssue && tool.includes("log_time") && state.done_comment && !state.time_logged) {
    state.time_logged = true
    changed = true
  }
  if ((tool.includes("update_knowledge") || tool.includes("create_knowledge")) && !state.knowledge_reviewed) {
    state.knowledge_reviewed = true
    changed = true
  }

  return changed
}

// ---------------------------------------------------------------------------
// Turn gate
// ---------------------------------------------------------------------------

// V2 exposes ctx.session.prompt; V1 exposed ctx.client.session.prompt.
export async function promptSession(ctx: any, sessionId: string, text: string): Promise<void> {
  if (typeof ctx?.session?.prompt === "function") {
    await ctx.session.prompt({ sessionID: sessionId, text })
    return
  }
  if (typeof ctx?.client?.session?.prompt === "function") {
    await ctx.client.session.prompt({ sessionID: sessionId, text })
  }
}

// Only the session that opened the workflow may be pushed to finish it:
// subagent sessions (scout, dev, expert) cannot drive the state machine and
// would only loop on tools they are not allowed to call.
function ownsWorkflowTurn(state: IssueDevState, sessionId: string): boolean {
  if (state.session_id) return state.session_id === sessionId
  return true
}

function closeWorkflow(state: IssueDevState): void {
  state.active = false
  state.completed_at = Date.now()
  saveState(state)
}

// The workflow ends with the issue Done. Best effort — an unreachable TaskR
// just closes the workflow.
async function closeWorkflowWithStatuses(ctx: any, state: IssueDevState): Promise<void> {
  const workspaceId = typeof state.workspace_id === "string" ? state.workspace_id : null
  const issueId = typeof state.issue_id === "string" ? state.issue_id : null

  if (workspaceId !== null && issueId !== null) {
    const client = await createTaskrClient(ctx)
    if (client !== null) {
      const issue = await client.getIssue(workspaceId, issueId)
      const statuses = issue !== null ? await client.listStatuses(workspaceId) : null

      if (issue !== null && statuses !== null && issue.status?.category !== "done") {
        const done = statusByCategory(statuses, "done")
        if (done !== null) await client.moveIssue(workspaceId, issueId, done.id)
      }
    }
  }

  closeWorkflow(state)
}

export async function turnGate(ctx: any, sessionId: string): Promise<void> {
  const state = loadState()
  if (!state.active) return

  const steps = missingSteps(state)
  if (steps.length === 0) {
    await closeWorkflowWithStatuses(ctx, state)
    return
  }

  if (!ownsWorkflowTurn(state, sessionId)) return

  await promptSession(
    ctx,
    sessionId,
    `issue-dev-gate: the TaskR issue ${issueLabel(state)} workflow is incomplete:\n` +
      `- ${steps.join("\n- ")}\n` +
      `Finish them (see the taskr-issue-dev skill), or run issue_dev action="cancel" if this is not a TaskR issue workflow.`,
  )
}

// ---------------------------------------------------------------------------
// Plugin entrypoints
// ---------------------------------------------------------------------------

// OpenCode v1 entrypoint: server(ctx) -> hooks.
export const IssueDevPlugin = async (ctx: any): Promise<
  Record<string, (input: any, output: any) => Promise<void>>
> => {
  setLocation(ctx?.location?.directory ?? ctx?.directory ?? ctx?.cwd)

  return {
    "tool.execute.before": async (input, output) => {
      const state = loadState()
      if (!state.active) return
      const tool = String(input?.tool ?? "")
      await preToolChecks(state, tool, input?.args ?? output?.args ?? {})
    },
    "tool.execute.after": async (input, output) => {
      const state = loadState()
      if (!state.active) return
      if (recordProgress(state, String(input?.tool ?? ""), { input: input?.args, ...output })) {
        saveState(state)
      }
    },
    event: async (event) => {
      if (event.type !== "session.idle") return
      const sessionId = String(event.sessionID ?? event.session_id ?? "default")
      try {
        await turnGate(ctx, sessionId)
      } catch {
        // One failed gate must never stop the plugin.
      }
    },
  }
}

// OpenCode v2 entrypoint.
const setup = async (ctx: any) => {
  setLocation(ctx?.location?.directory ?? ctx?.directory ?? ctx?.cwd)

  // Register the issue_dev tool for the state machine.
  await ctx.tool.transform((editor: any) => {
    editor.add({
      name: "issue_dev",
      description:
        "Manage the TaskR issue development workflow state machine. Actions: start (requires workspaceId + issueId; moves the issue to In Progress), status, knowledge-reviewed, cancel.",
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
      execute: async (
        input: { action: string; workspaceId?: string; issueId?: string },
        context?: { sessionID?: string; agent?: string },
      ) => {
        const result = await cliMain({ ...input, sessionId: context?.sessionID, agent: context?.agent }, ctx)
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
    if (tool === "issue_dev" || tool.endsWith("_issue_dev")) return
    await preToolChecks(state, tool, event?.input ?? event?.args ?? {})
  })

  // Post-tool: record progress.
  await ctx.tool.hook("execute.after", async (event: any) => {
    const state = loadState()
    if (!state.active) return
    if (recordProgress(state, String(event?.tool ?? ""), event)) {
      saveState(state)
    }
  })

  // Turn gate: refuse to end the turn while a workflow is incomplete.
  const controller = new AbortController()
  void (async () => {
    try {
      for await (const event of ctx.event.subscribe({ signal: controller.signal })) {
        if (event.type !== "session.idle") continue
        const sessionId = String((event as any).sessionID ?? (event as any).session_id ?? "default")
        try {
          await turnGate(ctx, sessionId)
        } catch {
          // One failed gate must never end the subscription.
        }
      }
    } catch {
      // Stream aborted on unload.
    }
  })()
  return () => controller.abort()
}

export default { id: "issue-dev", server: IssueDevPlugin, setup }
