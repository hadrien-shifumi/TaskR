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
// 5. TaskR statuses: the workflow drives the issue status — In Progress on
//    start, Done at closure, parents lifted out of Backlog while a child
//    moves forward, move_issue to Done refused while children stay open.
//    All best effort over the TaskR MCP server (./taskr.ts): an outage
//    skips the sync, it never breaks the workflow.
//
// 6. Scratch context: shared context persists in .opencode/scratch/ — the
//    epic dossier scaffolded at start (never overwritten) and the plan/
//    done comment mirrors — so fresh-context subagents re-read files
//    instead of receiving the context inline in each brief.
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
import {
  createTaskrClient,
  type TaskrClient,
  type TaskrIssue,
  type TaskrIssueRef,
  type TaskrStatus,
} from "./taskr"

const PLAN_MARKER = "taskr:plan"
const DONE_MARKER = "taskr:done"

// Editing these paths counts as implementation and requires the plan comment.
// .opencode/, docs and scratch files are excluded on purpose.
const SOURCE_FILE = /(^|\/)(app|routes|database|config|resources\/js|resources\/views|tests)\//

// ---------------------------------------------------------------------------
// State management (one JSON file per project root)
// ---------------------------------------------------------------------------

export type IssueDevState = {
  active: boolean
  workspace_id?: string
  issue_id?: string
  // Parent (epic) issue id, recorded at start when the started issue has
  // one — the epic scratch dossier is keyed on it.
  epic_id?: string
  started_at?: number
  session_id?: string
  agent?: string
  knowledge_searched: boolean
  code_explored: boolean
  plan_comment: boolean
  done_comment: boolean
  time_logged: boolean
  knowledge_reviewed: boolean
  done_blocked_notified?: boolean
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
      epic_id: parsed.epic_id,
      started_at: parsed.started_at,
      session_id: parsed.session_id,
      agent: parsed.agent,
      knowledge_searched: parsed.knowledge_searched ?? false,
      code_explored: parsed.code_explored ?? false,
      plan_comment: parsed.plan_comment ?? false,
      done_comment: parsed.done_comment ?? false,
      time_logged: parsed.time_logged ?? false,
      knowledge_reviewed: parsed.knowledge_reviewed ?? false,
      done_blocked_notified: parsed.done_blocked_notified ?? false,
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
    epic_id: undefined,
    started_at: Date.now(),
    session_id: sessionId,
    agent,
    knowledge_searched: false,
    code_explored: false,
    plan_comment: false,
    done_comment: false,
    time_logged: false,
    knowledge_reviewed: false,
    done_blocked_notified: false,
  }
}

// ---------------------------------------------------------------------------
// Scratch context — persisted .opencode/scratch/ files
//
// Subagents are dispatched with a fresh context every time: the shared
// context lives in files under the (git-ignored) scratch folder instead of
// being re-inlined in each brief. Two kinds are maintained here:
//
// 1. Epic dossier: <scratchRoot>/epic-<epicId>/{kb.md,patterns.md,
//    review-checklist.md}, scaffolded at start when the started issue has
//    a parent, filled by reader/scout/reviewer along the epic's tickets.
// 2. Plan/done mirrors: <scratchRoot>/{plan,done}-<issueId>.md, written as
//    soon as the marked comment is posted so any subagent without TaskR
//    access can re-read it, and the writer can draft the comment in this
//    very file, then post it from there.
//
// Every filesystem operation is best effort — a failed write never breaks
// the workflow. Stub bodies are French on purpose: they are user-facing
// working notes, unlike the plugin messages.
// ---------------------------------------------------------------------------

export function scratchRoot(): string {
  return path.join(projectRoot(), ".opencode", "scratch")
}

// Issue ids end up in file and folder names; only accept TaskR ULID-shaped
// ids so a garbled parent ref can never write outside the scratch folder.
const SAFE_ISSUE_ID = /^[0-9A-Za-z]{20,}$/

function stubDate(): string {
  return new Date().toISOString().slice(0, 10)
}

const EPIC_STUBS: Array<{ file: string; body: (epicId: string) => string }> = [
  {
    file: "kb.md",
    body: (epicId) => `# Contexte EPIC — base de connaissances (${epicId})

> Cache — la source de vérité reste TaskR et le code.

- **But** : contexte de l'EPIC relu par chaque sous-agent avant tout travail : spec, arbitrages, ULIDs (workspace, projet, issues), gotchas.
- **Rempli par** : l'agent \`reader\` via l'orchestrateur (dump des pages knowledge pertinentes).
- **Scaffoldé le** : ${stubDate()} par le plugin issue-dev (stub à remplacer).
`,
  },
  {
    file: "patterns.md",
    body: (epicId) => `# Contexte EPIC — patterns du code (${epicId})

> Cache — la source de vérité reste TaskR et le code.

- **But** : signatures observées au format \`fichier:ligne\`, patterns de tests et factories, pour éviter une re-exploration à chaque ticket.
- **Rempli par** : l'agent \`scout\` via l'orchestrateur.
- **Scaffoldé le** : ${stubDate()} par le plugin issue-dev (stub à remplacer).
- **Attention** : les numéros de ligne dérivent — régénérer ce fichier à chaque changement de domaine.
`,
  },
  {
    file: "review-checklist.md",
    body: (epicId) => `# Contexte EPIC — checklist de revue (${epicId})

> Cache — la source de vérité reste TaskR et le code.

- **But** : invariants à vérifier sur chaque ticket de l'EPIC (multi-tenancy, ULIDs, validation scopée, etc.).
- **Rempli par** : l'agent \`reviewer\` via l'orchestrateur.
- **Scaffoldé le** : ${stubDate()} par le plugin issue-dev (stub à remplacer).
`,
  },
]

export type EpicScaffold = { dir: string; created: string[] }

// Scaffolds the epic dossier with its three context stubs, each only when
// absent: the dossier is filled along the epic's tickets and must survive
// every later start. Returns null when the filesystem refuses — the start
// itself still succeeds.
export function scaffoldEpicContext(epicId: string): EpicScaffold | null {
  try {
    const dir = path.join(scratchRoot(), `epic-${epicId}`)
    mkdirSync(dir, { recursive: true })
    const created: string[] = []
    for (const stub of EPIC_STUBS) {
      const file = path.join(dir, stub.file)
      if (existsSync(file)) continue
      writeFileSync(file, stub.body(epicId))
      created.push(stub.file)
    }
    return { dir, created }
  } catch {
    return null
  }
}

// Mirrors a posted plan/done comment body verbatim into the scratch folder.
// Overwriting is fine — one marked comment per kind is enforced upstream.
function mirrorComment(kind: "plan" | "done", issueId: string, body: string): void {
  try {
    mkdirSync(scratchRoot(), { recursive: true })
    writeFileSync(path.join(scratchRoot(), `${kind}-${issueId}.md`), body)
  } catch {
    // The mirror is a convenience: a failed write never breaks the flow.
  }
}

export type EpicContext = { reachable: boolean; epicId: string | null; scaffold: EpicScaffold | null }

// Best effort epic context: when the started issue has a parent, remember
// it in the state and scaffold the shared scratch dossier. An unreachable
// TaskR skips the scaffold — the start never fails because of it.
async function syncEpicContext(state: IssueDevState, ctx?: any): Promise<EpicContext> {
  const workspaceId = typeof state.workspace_id === "string" ? state.workspace_id : null
  const issueId = typeof state.issue_id === "string" ? state.issue_id : null
  if (workspaceId === null || issueId === null) return { reachable: false, epicId: null, scaffold: null }

  const client = await createTaskrClient(ctx)
  if (client === null) return { reachable: false, epicId: null, scaffold: null }

  const issue = await client.getIssue(workspaceId, issueId)
  if (issue === null) return { reachable: false, epicId: null, scaffold: null }

  const parentId = issue.parent !== null ? issue.parent.id : null
  if (parentId === null || !SAFE_ISSUE_ID.test(parentId)) return { reachable: true, epicId: null, scaffold: null }

  state.epic_id = parentId
  return { reachable: true, epicId: parentId, scaffold: scaffoldEpicContext(parentId) }
}

// ---------------------------------------------------------------------------
// TaskR status coherence (start -> In Progress, closure -> Done,
// parents lifted out of Backlog, parents blocked over open children)
// ---------------------------------------------------------------------------

// Done and Cancelled both close a child: a cancelled child no longer
// blocks its parent, mirroring TaskR's own dependency semantics.
const CLOSED_CATEGORIES = new Set(["done", "cancelled"])

function statusByCategory(statuses: TaskrStatus[], category: string): TaskrStatus | null {
  for (const status of statuses) {
    if (status.category === category) return status
  }
  return null
}

// get_issue reports the parent and children with their status *name* only.
// Status names are unique per workspace, so the category resolves through
// the workspace status list.
function categoryOfStatusName(statuses: TaskrStatus[], name: string | null): string | null {
  if (typeof name !== "string" || name === "") return null
  for (const status of statuses) {
    if (status.name === name) return status.category
  }
  return null
}

function blockingChildren(issue: TaskrIssue, statuses: TaskrStatus[]): TaskrIssueRef[] {
  return issue.children.filter((child) => {
    const category = categoryOfStatusName(statuses, child.statusName)
    return category !== null && !CLOSED_CATEGORIES.has(category)
  })
}

function formatChildren(children: TaskrIssueRef[]): string {
  return children.map((child) => `- ${child.title} (${child.id}, ${child.statusName ?? "unknown"})`).join("\n")
}

// A parent cannot stay in Backlog once a child moves forward (or closes):
// lift it to In Progress. Best effort — a failed move only skips the lift.
async function liftBacklogParent(
  client: TaskrClient,
  workspaceId: string,
  issue: TaskrIssue,
  statuses: TaskrStatus[],
  notes?: string[],
): Promise<void> {
  const parent = issue.parent
  if (parent === null) return
  if (categoryOfStatusName(statuses, parent.statusName) !== "backlog") return

  const inProgress = statusByCategory(statuses, "in_progress")
  if (inProgress === null) return

  const moved = await client.moveIssue(workspaceId, parent.id, inProgress.id)
  if (moved === null) return

  const note = `parent ${parent.id} (${parent.title}) moved to ${inProgress.name} — a child moved forward`
  if (notes !== undefined) notes.push(note)
  else process.stderr.write(`issue-dev taskr: ${note}\n`)
}

export type StartStatusSync = { reachable: boolean; notes: string[] }

// Rule 1: a started issue is In Progress. Rule 3: its parent leaves the
// Backlog. Best effort — an unreachable TaskR never fails the start.
export async function syncStartStatuses(workspaceId: string, issueId: string, ctx?: any): Promise<StartStatusSync> {
  const client = await createTaskrClient(ctx)
  if (client === null) return { reachable: false, notes: [] }

  const issue = await client.getIssue(workspaceId, issueId)
  const statuses = issue !== null ? await client.listStatuses(workspaceId) : null
  if (issue === null || statuses === null) return { reachable: false, notes: [] }

  const notes: string[] = []

  if (issue.status?.category !== "in_progress") {
    const inProgress = statusByCategory(statuses, "in_progress")
    if (inProgress !== null) {
      const moved = await client.moveIssue(workspaceId, issueId, inProgress.id)
      if (moved !== null) notes.push(`issue ${issueId} moved to ${inProgress.name} (was ${issue.status?.name})`)
    }
  }

  await liftBacklogParent(client, workspaceId, issue, statuses, notes)
  return { reachable: true, notes }
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
    steps.push(
      `post the plan comment (draft it in .opencode/scratch/plan-${issueLabel(state)}.md — it is mirrored there automatically once posted; the add_comment body starts with \`<!-- ${PLAN_MARKER} -->\`)`,
    )
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
      const epic = await syncEpicContext(started, ctx)
      if (epic.epicId !== null) saveState(started)

      const statusLine = !sync.reachable
        ? "TaskR statuses: server unreachable — statuses left untouched."
        : sync.notes.length > 0
          ? `TaskR statuses: ${sync.notes.join("; ")}.`
          : `TaskR statuses: issue ${issueId} already in progress.`

      // The epic line only appears when the dossier exists on disk: a
      // filesystem failure must not advertise a path nobody can read.
      const epicLine =
        epic.epicId !== null && epic.scaffold !== null
          ? epic.scaffold.created.length > 0
            ? `Epic context scaffolded at .opencode/scratch/epic-${epic.epicId}/ (kb.md, patterns.md, review-checklist.md) — reader fills kb.md, scout fills patterns.md; reference these files in subagent briefs.`
            : `Epic context available at .opencode/scratch/epic-${epic.epicId}/ (kb.md, patterns.md, review-checklist.md) — reference these files in subagent briefs.`
          : null

      return {
        ok: true,
        message:
          `issue-dev: workflow opened for issue ${issueId}.\n` +
          `${statusLine}\n` +
          (epicLine !== null ? `${epicLine}\n` : "") +
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

// move_issue coherence, enforced on every move whatever the target issue:
// a parent cannot close over open children (rule 4), and a parent never
// stays in Backlog while a child moves forward (rule 3). An unreachable
// TaskR never blocks a move — the status guard only ever restricts moves
// it can verify.
async function guardMoveIssue(state: IssueDevState, input: Record<string, unknown>, ctx?: any): Promise<void> {
  const issueId = targetIssueId(input)
  if (issueId === null) return

  const workspaceId = firstString(input, ["workspace_id", "workspaceId"]) ?? state.workspace_id ?? null
  if (workspaceId === null) return

  const statusId = firstString(input, ["status_id", "statusId", "status"])
  if (statusId === null) return // The server rejects the call anyway.

  const client = await createTaskrClient(ctx)
  if (client === null) return

  const [statuses, issue] = await Promise.all([
    client.listStatuses(String(workspaceId)),
    client.getIssue(String(workspaceId), issueId),
  ])
  if (statuses === null || issue === null) return

  const target = statuses.find((status) => status.id === statusId)
  if (target === undefined) return // Unknown status: the server validates.

  if (target.category === "done") {
    const blockers = blockingChildren(issue, statuses)
    if (blockers.length > 0) {
      throw new Error(
        `issue-dev: issue ${issueId} cannot move to ${target.name} — these children are not done yet:\n` +
          `${formatChildren(blockers)}\n` +
          `Move the children to Done (or cancel them) first, then move the parent.`,
      )
    }
  }

  if (target.category === "done" || target.category === "in_progress") {
    await liftBacklogParent(client, String(workspaceId), issue, statuses)
  }
}

export async function preToolChecks(
  state: IssueDevState,
  tool: string,
  input: Record<string, unknown>,
  ctx?: any,
): Promise<void> {
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

  // Code Mode wraps MCP calls in `execute`, but the inner tool fires its own
  // execute.before event with the real name and arguments. Enforcing here too
  // would only re-check a code string we cannot reliably parse.
  if (tool.includes("execute") || tool.includes("typescript") || tool.includes("codemode")) return

  // move_issue coherence: parents cannot close over open children, and a
  // Backlog parent is lifted when a child moves forward.
  if (tool.includes("move_issue")) {
    await guardMoveIssue(state, input, ctx)
  }

  if (isTaskrTool(tool) && targetsWorkflow(state, input)) {
    // log_time before done comment.
    if (tool.includes("log_time") && !state.done_comment) {
      throw new Error(
        "issue-dev: log_time comes after the done comment — the logged duration runs from the issue's first comment to now, so the implementation must be finished and reported first.",
      )
    }

    // add_comment marker enforcement.
    const isAddComment = tool.includes("add_comment")
    if (isAddComment) {
      const hasPlan = String(input.body ?? "").includes(`<!-- ${PLAN_MARKER} -->`)
      const hasDone = String(input.body ?? "").includes(`<!-- ${DONE_MARKER} -->`)

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

export function recordProgress(state: IssueDevState, tool: string, event: any): boolean {
  const input = (event?.input ?? event?.args ?? {}) as Record<string, unknown>
  // V2 reports failures through status; V1 used an error flag. Honour both.
  if (event?.status === "error" || event?.error === true || event?.result?.metadata?.error === true) return false

  let changed = false

  // TaskR connector tools (MCP tools arrive with taskr_ prefix). The inner MCP
  // tool fires its own execute.after event, so the outer `execute` call is
  // ignored here: it carries a code string, not the tool arguments.
  if (isTaskrTool(tool)) {
    const isWorkflowIssue = targetsWorkflow(state, input)

    if (tool.includes("search_knowledge") && !state.knowledge_searched) {
      state.knowledge_searched = true
      changed = true
    }
    if ((tool.includes("update_knowledge") || tool.includes("create_knowledge")) && !state.knowledge_reviewed) {
      state.knowledge_reviewed = true
      changed = true
    }
    if (isWorkflowIssue && tool.includes("add_comment")) {
      const body = String(input.body ?? "")
      // Mirror the exact body to the scratch folder — whether or not the
      // flag flips — so the latest posted comment always wins on disk.
      const issueId = typeof state.issue_id === "string" ? state.issue_id : null
      if (issueId !== null && SAFE_ISSUE_ID.test(issueId)) {
        if (body.includes(`<!-- ${PLAN_MARKER} -->`)) mirrorComment("plan", issueId, body)
        if (body.includes(`<!-- ${DONE_MARKER} -->`)) mirrorComment("done", issueId, body)
      }
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

// The writer and reporter can neither start nor close an issue workflow
// (issue_dev is denied to them), so nudging them toward it is a dead end.
const WORKFLOW_EXEMPT_AGENTS = new Set(["writer", "reporter"])

export function shouldNudge(agent: string): boolean {
  return !WORKFLOW_EXEMPT_AGENTS.has(agent)
}

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

async function sessionAgent(ctx: any, sessionId: string): Promise<string> {
  try {
    if (typeof ctx?.session?.get === "function") {
      const session = await ctx.session.get({ sessionID: sessionId })
      return String(session?.agent ?? "")
    }
  } catch {
    // Unknown session: fall through and treat it as a workflow owner.
  }
  return ""
}

// Only the session that opened the workflow may be pushed to finish it.
// Every other session (writer, reporter, dev, parallel work) cannot act on
// it and would only loop on tools it is not allowed to call.
async function ownsWorkflowTurn(ctx: any, state: IssueDevState, sessionId: string): Promise<boolean> {
  if (state.session_id) return state.session_id === sessionId
  return shouldNudge(await sessionAgent(ctx, sessionId))
}

function closeWorkflow(state: IssueDevState): void {
  state.active = false
  state.completed_at = Date.now()
  saveState(state)
}

// Rule 2: the workflow ends with the issue Done. Rule 4: never over open
// children — the session is told once which children hold the closure
// (done_blocked_notified prevents a notification loop), then the workflow
// closes anyway: the remaining work lives in the children, not here.
// Best effort — an unreachable TaskR just closes the workflow.
async function closeWorkflowWithStatuses(ctx: any, sessionId: string, state: IssueDevState): Promise<void> {
  const workspaceId = typeof state.workspace_id === "string" ? state.workspace_id : null
  const issueId = typeof state.issue_id === "string" ? state.issue_id : null

  if (workspaceId !== null && issueId !== null) {
    const client = await createTaskrClient(ctx)
    if (client !== null) {
      const issue = await client.getIssue(workspaceId, issueId)
      const statuses = issue !== null ? await client.listStatuses(workspaceId) : null

      if (issue !== null && statuses !== null && issue.status?.category !== "done") {
        const blockers = blockingChildren(issue, statuses)

        if (blockers.length > 0) {
          if (!state.done_blocked_notified && (await ownsWorkflowTurn(ctx, state, sessionId))) {
            state.done_blocked_notified = true
            saveState(state)
            await promptSession(
              ctx,
              sessionId,
              `issue-dev-gate: every step of issue ${issueId} is complete, but it was not moved to Done — these children are not done yet:\n` +
                `${formatChildren(blockers)}\n` +
                `Move the children to Done (or cancel them), then move_issue ${issueId} to Done. The workflow is closed; finish the children as their own work.`,
            )
          }
          closeWorkflow(state)
          return
        }

        const done = statusByCategory(statuses, "done")
        if (done !== null) {
          const moved = await client.moveIssue(workspaceId, issueId, done.id)
          if (moved !== null) {
            await liftBacklogParent(client, workspaceId, issue, statuses)
          }
        }
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
    await closeWorkflowWithStatuses(ctx, sessionId, state)
    return
  }

  if (!(await ownsWorkflowTurn(ctx, state, sessionId))) return

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
      await preToolChecks(state, tool, input?.args ?? output?.args ?? {}, ctx)
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
      if (!shouldNudge(String(input?.agent ?? ""))) return
      if (isTaskrTool(tool) && !tool.includes("issue_dev")) {
        inject(output, block([
          "The TaskR connector is in use with no declared issue workflow.",
          `If this task is a TaskR issue: run issue_dev action="start" workspaceId="<workspace_ulid>" issueId="<issue_ulid>" and follow the taskr-issue-dev skill (research, plan comment, implement, done comment, time entry, knowledge review).`,
          "If it is not (triage, admin, read-only lookup): ignore this reminder.",
        ]))
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
        "Manage the TaskR issue development workflow state machine. Actions: start (requires workspaceId + issueId; moves the issue to In Progress and lifts a Backlog parent), status, knowledge-reviewed, cancel.",
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
    // Allow the issue_dev tool itself.
    if (tool === "issue_dev" || tool.endsWith("_issue_dev")) return
    await preToolChecks(state, tool, event?.input ?? event?.args ?? {}, ctx)
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

    // Not in a workflow, yet the TaskR connector is being used. The writer and
    // reporter cannot run issue_dev, so the reminder would only mislead them.
    if (!shouldNudge(String(event?.agent ?? ""))) return
    if (isTaskrTool(tool) && !tool.includes("issue_dev")) {
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
