import { afterEach, describe, expect, test } from "bun:test"
import crypto from "node:crypto"
import { mkdirSync, rmSync, writeFileSync } from "node:fs"
import { homedir } from "node:os"
import path from "node:path"
import {
  cliMain,
  freshState,
  loadState,
  preToolChecks,
  recordProgress,
  setLocation,
  statePath,
  turnGate,
  type IssueDevState,
} from "./index"
import {
  extractRpcMessage,
  resetTaskrTransport,
  setTaskrConfig,
  setTaskrTransport,
  type TaskrTransport,
} from "./taskr"

const WORKSPACE = "01m52m8k73eh8cqby9zdq4svxy"
const WORKFLOW_ISSUE = "01m63jakdrx01whs4bjhv86gwv"
const OTHER_ISSUE = "01m6bdqn45mbwcwa47vpdv7wds"

const STATUS_BACKLOG = "01stbacklog0000000001"
const STATUS_PROGRESS = "01stprogress00000001"
const STATUS_DONE = "01stdone00000000001"
const STATUSES = [
  { id: STATUS_BACKLOG, name: "Backlog", category: "backlog", position: 1 },
  { id: STATUS_PROGRESS, name: "In Progress", category: "in_progress", position: 2 },
  { id: STATUS_DONE, name: "Done", category: "done", position: 3 },
]

function state(overrides: Partial<IssueDevState> = {}): IssueDevState {
  return { ...freshState(WORKSPACE, WORKFLOW_ISSUE, "ses_owner", "orchestrator"), ...overrides }
}

function expectedStatePath(directory: string): string {
  const hash = crypto.createHash("sha1").update(directory).digest("hex").slice(0, 12)
  return path.join(homedir(), ".cache", "opencode-issue-dev", `${hash}.json`)
}

// ---------------------------------------------------------------------------
// In-memory TaskR world speaking the MCP JSON-RPC wire protocol.
// ---------------------------------------------------------------------------

type FakeIssue = { id: string; title: string; statusId: string }
type FakeTaskr = { issues: Record<string, FakeIssue>; moves: Array<{ issueId: string; statusId: string }> }

function installFakeTaskr(issues: FakeIssue[], options: { sse?: boolean } = {}): FakeTaskr {
  const world: FakeTaskr = { issues: {}, moves: [] }
  for (const issue of issues) world.issues[issue.id] = { ...issue }

  const detail = (issueId: string) => {
    const issue = world.issues[issueId]
    if (issue === undefined) return null
    const status = STATUSES.find((candidate) => candidate.id === issue.statusId)
    return {
      id: issue.id,
      title: issue.title,
      status: { id: status?.id ?? "", name: status?.name ?? "", category: status?.category ?? "" },
    }
  }

  // The MCP tools/call result envelope the real server emits: the payload
  // lives in structuredContent and is mirrored as a JSON text block.
  const toolEnvelope = (payload: unknown, isError = false) => ({
    content: [{ type: "text", text: JSON.stringify(payload) }],
    structuredContent: payload,
    isError,
  })

  const transport: TaskrTransport = async (_url, request) => {
    const message = JSON.parse(request.body) as { id?: number; method: string; params?: any }
    const respond = (result: unknown, headers: Record<string, string> = {}) => {
      const body = JSON.stringify({ jsonrpc: "2.0", id: message.id, result })
      if (options.sse === true) {
        return {
          status: 200,
          headers: { "content-type": "text/event-stream", ...headers },
          body: `event: message\ndata: ${body}\n\n`,
        }
      }
      return { status: 200, headers: { "content-type": "application/json", ...headers }, body }
    }

    if (message.method === "initialize") {
      return respond(
        { protocolVersion: "2026-07-28", capabilities: {}, serverInfo: { name: "taskr", version: "test" } },
        { "mcp-session-id": "sess-fake" },
      )
    }
    if (message.method === "notifications/initialized") {
      return { status: 202, headers: {}, body: "" }
    }
    if (message.method === "tools/call") {
      const name = String(message.params?.name ?? "")
      const args = (message.params?.arguments ?? {}) as Record<string, unknown>
      if (name === "list_statuses") return respond(toolEnvelope({ statuses: STATUSES }))
      if (name === "get_issue") return respond(toolEnvelope({ issue: detail(String(args.issue_id)) }))
      if (name === "move_issue") {
        const target = world.issues[String(args.issue_id)]
        if (target === undefined) return respond(toolEnvelope({ issue: null }))
        target.statusId = String(args.status_id)
        world.moves.push({ issueId: target.id, statusId: target.statusId })
        return respond(toolEnvelope({ issue: detail(target.id) }))
      }
      return respond(toolEnvelope({ error: "unknown_tool" }, true))
    }
    return {
      status: 200,
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ jsonrpc: "2.0", id: message.id, error: { code: -32601, message: "method not found" } }),
    }
  }

  setTaskrConfig({ url: "https://taskr.test/mcp", headers: { Authorization: "Bearer test-token" } })
  setTaskrTransport(transport)
  return world
}

function installUnreachableTaskr(): void {
  setTaskrConfig({ url: "https://taskr.test/mcp", headers: { Authorization: "Bearer test-token" } })
  setTaskrTransport(async () => {
    throw new Error("network down")
  })
}

function resetTaskr(): void {
  setTaskrConfig(null)
  resetTaskrTransport()
}

// Captures every prompt the gate sends, as the OpenCode runtime would.
function gateCtx(): { ctx: any; prompts: string[] } {
  const prompts: string[] = []
  const ctx = {
    session: {
      prompt: async (args: any) => {
        prompts.push(String(args.text))
      },
    },
  }
  return { ctx, prompts }
}

// Isolated state file per test group.
const directories: string[] = []

function useTemporaryLocation(): string {
  const directory = path.join("/tmp", `taskr-issue-dev-test-${crypto.randomUUID()}`)
  directories.push(directory)
  setLocation(directory)
  return directory
}

afterEach(() => {
  for (const directory of directories.splice(0)) {
    rmSync(directory, { recursive: true, force: true })
  }
  rmSync(path.dirname(statePath()), { recursive: true, force: true })
  resetTaskr()
})

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

describe("state", () => {
  test("state path is scoped to the location directory, not process.cwd()", () => {
    const directory = useTemporaryLocation()
    expect(statePath()).toBe(expectedStatePath(directory))
    expect(statePath()).not.toBe(expectedStatePath(process.cwd()))
  })

  test("a legacy state file without the removed flags loads with defaults", () => {
    const directory = useTemporaryLocation()
    const legacy = {
      active: true,
      workspace_id: WORKSPACE,
      issue_id: WORKFLOW_ISSUE,
      knowledge_searched: true,
      code_explored: true,
      epic_id: "01m6c6e1e0x6rm6rybnv91zybj",
      plan_comment: true,
      done_comment: false,
      time_logged: false,
      knowledge_reviewed: false,
    }
    mkdirSync(path.dirname(expectedStatePath(directory)), { recursive: true })
    writeFileSync(expectedStatePath(directory), JSON.stringify(legacy))

    const loaded = loadState()

    expect(loaded.active).toBe(true)
    expect(loaded.plan_comment).toBe(true)
    expect(loaded.done_comment).toBe(false)
    expect((loaded as any).epic_id).toBeUndefined()
  })
})

// ---------------------------------------------------------------------------
// issue_dev actions
// ---------------------------------------------------------------------------

describe("cliMain start", () => {
  test("rejects malformed ULIDs", async () => {
    useTemporaryLocation()
    const result = await cliMain({ action: "start", workspaceId: "short", issueId: WORKFLOW_ISSUE })
    expect(result.ok).toBe(false)
    expect(loadState().active).toBe(false)
  })

  test("opens the workflow and moves the issue to In Progress", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr([{ id: WORKFLOW_ISSUE, title: "Ticket", statusId: STATUS_BACKLOG }])

    const result = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })

    expect(result.ok).toBe(true)
    expect(loadState().active).toBe(true)
    expect(world.moves).toEqual([{ issueId: WORKFLOW_ISSUE, statusId: STATUS_PROGRESS }])
  })

  test("leaves an issue already in progress untouched", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr([{ id: WORKFLOW_ISSUE, title: "Ticket", statusId: STATUS_PROGRESS }])

    await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })

    expect(world.moves).toEqual([])
  })

  test("still opens the workflow when TaskR is unreachable", async () => {
    useTemporaryLocation()
    installUnreachableTaskr()

    const result = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })

    expect(result.ok).toBe(true)
    expect(loadState().active).toBe(true)
  })
})

describe("cliMain status / knowledge-reviewed / cancel", () => {
  test("status reports no active workflow", async () => {
    useTemporaryLocation()
    const result = await cliMain({ action: "status" })
    expect(result.ok).toBe(true)
    expect(result.message).toContain("no active workflow")
  })

  test("status lists the remaining steps", async () => {
    useTemporaryLocation()
    installFakeTaskr([{ id: WORKFLOW_ISSUE, title: "Ticket", statusId: STATUS_PROGRESS }])
    await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })

    const result = await cliMain({ action: "status" })

    expect(result.message).toContain(WORKFLOW_ISSUE)
    expect(result.message).toContain("plan comment")
    expect(result.message).toContain("knowledge base")
  })

  test("knowledge-reviewed refuses without a workflow and attests with one", async () => {
    useTemporaryLocation()
    expect((await cliMain({ action: "knowledge-reviewed" })).ok).toBe(false)

    const current = state()
    const directory = useTemporaryLocation()
    mkdirSync(path.dirname(expectedStatePath(directory)), { recursive: true })
    writeFileSync(expectedStatePath(directory), JSON.stringify(current))

    const result = await cliMain({ action: "knowledge-reviewed" })
    expect(result.ok).toBe(true)
    expect(loadState().knowledge_reviewed).toBe(true)
  })

  test("cancel stands the gate down", async () => {
    useTemporaryLocation()
    const directory = useTemporaryLocation()
    mkdirSync(path.dirname(expectedStatePath(directory)), { recursive: true })
    writeFileSync(expectedStatePath(directory), JSON.stringify(state()))

    await cliMain({ action: "cancel" })

    expect(loadState().active).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// Pre-tool ordering rules
// ---------------------------------------------------------------------------

describe("source edits", () => {
  test("are refused before the plan comment", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "edit", { file_path: "app/Actions/Foo.php" }),
    ).rejects.toThrow("plan comment")
  })

  test("are allowed after the plan comment", async () => {
    const current = state({ plan_comment: true })
    await expect(
      preToolChecks(current, "edit", { file_path: "app/Actions/Foo.php" }),
    ).resolves.toBeUndefined()
  })

  test("outside source paths are never blocked", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "write", { file_path: ".opencode/notes.md" }),
    ).resolves.toBeUndefined()
  })
})

describe("comment marker rules", () => {
  test("a comment on another issue is allowed without a marker", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: OTHER_ISSUE,
        body: "Note libre",
      }),
    ).resolves.toBeUndefined()
  })

  test("the plan marker is accepted right after start", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:plan -->\n[dev] Plan…",
      }),
    ).resolves.toBeUndefined()
  })

  test("the done marker is refused before the plan comment", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:done -->\n[dev] Terminé",
      }),
    ).rejects.toThrow("plan first")
  })

  test("a second plan or done comment is refused", async () => {
    await expect(
      preToolChecks(state({ plan_comment: true }), "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:plan -->\n[dev] Encore le plan",
      }),
    ).rejects.toThrow("already posted")

    await expect(
      preToolChecks(state({ plan_comment: true, done_comment: true }), "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:done -->\n[dev] Encore le done",
      }),
    ).rejects.toThrow("already posted")
  })
})

describe("comment signature gate", () => {
  test("a signed comment without a marker is allowed and changes no state", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "[scout] Synthèse fonctionnelle : objectif compris, points d'attention et questions ouvertes.",
      }),
    ).resolves.toBeUndefined()
    expect(current.plan_comment).toBe(false)
    expect(current.done_comment).toBe(false)
  })

  test("the signature is matched case-insensitively, bold or with a colon", async () => {
    for (const body of ["**[Scout]** Recherche", "scout: note libre", "[DEV] note", "[Orchestrator] suivi"]) {
      await expect(
        preToolChecks(state(), "taskr_add_comment", {
          workspace_id: WORKSPACE,
          issue_id: WORKFLOW_ISSUE,
          body,
        }),
      ).resolves.toBeUndefined()
    }
  })

  test("a comment without any signature is refused", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "Note libre sans signature",
      }),
    ).rejects.toThrow("signature")
  })

  test("a marker without a signature is still refused", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:plan -->\nPlan sans signature",
      }),
    ).rejects.toThrow("signature")
    expect(current.plan_comment).toBe(false)
  })

  test("markers still advance the state from signed comments only", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "[dev] Plan d'implémentation\n<!-- taskr:plan -->",
      }),
    ).resolves.toBeUndefined()

    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "[dev] Plan d'implémentation\n<!-- taskr:plan -->" },
    })).toBe(true)
    expect(current.plan_comment).toBe(true)

    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "[dev] Note libre sans marqueur",
      }),
    ).resolves.toBeUndefined()
    expect(current.done_comment).toBe(false)
  })
})

describe("log_time ordering", () => {
  test("is refused before the done comment", async () => {
    const current = state({ plan_comment: true })
    await expect(
      preToolChecks(current, "taskr_log_time", { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, hours: 1 }),
    ).rejects.toThrow("after the done comment")
  })

  test("is allowed after the done comment", async () => {
    const current = state({ plan_comment: true, done_comment: true })
    await expect(
      preToolChecks(current, "taskr_log_time", { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, hours: 1 }),
    ).resolves.toBeUndefined()
  })

  test("targets only the workflow issue", async () => {
    const current = state({ plan_comment: true })
    await expect(
      preToolChecks(current, "taskr_log_time", { workspace_id: WORKSPACE, issue_id: OTHER_ISSUE, hours: 1 }),
    ).resolves.toBeUndefined()
  })
})

// ---------------------------------------------------------------------------
// Progress recording
// ---------------------------------------------------------------------------

describe("recordProgress", () => {
  test("flips the plan and done flags from marked comments", () => {
    const current = state()

    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "<!-- taskr:plan -->\n[dev] Plan" },
    })).toBe(true)
    expect(current.plan_comment).toBe(true)

    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "<!-- taskr:done -->\n[dev] Done" },
    })).toBe(true)
    expect(current.done_comment).toBe(true)
  })

  test("ignores a done marker posted before the plan", () => {
    const current = state()
    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "<!-- taskr:done -->\n[dev] Done" },
    })).toBe(false)
    expect(current.done_comment).toBe(false)
  })

  test("flips the time flag after the done comment only", () => {
    const current = state({ plan_comment: true })
    expect(recordProgress(current, "taskr_log_time", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, hours: 1 },
    })).toBe(false)

    current.done_comment = true
    expect(recordProgress(current, "taskr_log_time", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, hours: 1 },
    })).toBe(true)
    expect(current.time_logged).toBe(true)
  })

  test("flips the knowledge flag on any knowledge write", () => {
    const current = state()
    expect(recordProgress(current, "taskr_update_knowledge", {
      input: { workspace_id: WORKSPACE, page_id: "01kb000000000000000000000", body: "…" },
    })).toBe(true)
    expect(current.knowledge_reviewed).toBe(true)
  })

  test("ignores failed calls and comments on other issues", () => {
    const current = state()
    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "<!-- taskr:plan -->" },
      status: "error",
    })).toBe(false)

    expect(recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: OTHER_ISSUE, body: "<!-- taskr:plan -->" },
    })).toBe(false)
    expect(current.plan_comment).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// Turn gate
// ---------------------------------------------------------------------------

describe("turnGate", () => {
  function persist(current: IssueDevState): string {
    const directory = useTemporaryLocation()
    mkdirSync(path.dirname(expectedStatePath(directory)), { recursive: true })
    writeFileSync(expectedStatePath(directory), JSON.stringify(current))
    return directory
  }

  test("does nothing without an active workflow", async () => {
    useTemporaryLocation()
    const { ctx, prompts } = gateCtx()
    await turnGate(ctx, "ses_owner")
    expect(prompts).toEqual([])
  })

  test("prompts the owner session while steps are missing", async () => {
    persist(state())
    const { ctx, prompts } = gateCtx()

    await turnGate(ctx, "ses_owner")

    expect(prompts).toHaveLength(1)
    expect(prompts[0]).toContain("workflow is incomplete")
    expect(prompts[0]).toContain("plan comment")
  })

  test("ignores sessions that do not own the workflow", async () => {
    persist(state())
    const { ctx, prompts } = gateCtx()

    await turnGate(ctx, "ses_dev_subagent")

    expect(prompts).toEqual([])
  })

  test("moves the issue to Done and closes once complete", async () => {
    const world = installFakeTaskr([{ id: WORKFLOW_ISSUE, title: "Ticket", statusId: STATUS_PROGRESS }])
    persist(state({ plan_comment: true, done_comment: true, time_logged: true, knowledge_reviewed: true }))
    const { ctx, prompts } = gateCtx()

    await turnGate(ctx, "ses_owner")

    expect(prompts).toEqual([])
    expect(world.moves).toEqual([{ issueId: WORKFLOW_ISSUE, statusId: STATUS_DONE }])
    expect(loadState().active).toBe(false)
  })

  test("closes even when TaskR is unreachable", async () => {
    installUnreachableTaskr()
    persist(state({ plan_comment: true, done_comment: true, time_logged: true, knowledge_reviewed: true }))
    const { ctx } = gateCtx()

    await turnGate(ctx, "ses_owner")

    expect(loadState().active).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// MCP wire format (taskr.ts)
// ---------------------------------------------------------------------------

describe("extractRpcMessage", () => {
  test("reads the JSON-RPC message out of an SSE body", () => {
    const body = `event: message\ndata: ${JSON.stringify({ jsonrpc: "2.0", id: 1, result: { ok: true } })}\n\n`
    const message = extractRpcMessage(body, "text/event-stream")
    expect(message?.result).toEqual({ ok: true })
  })
})
