import { afterEach, describe, expect, test } from "bun:test"
import crypto from "node:crypto"
import { existsSync, rmSync } from "node:fs"
import { homedir } from "node:os"
import path from "node:path"
import {
  cliMain,
  freshState,
  loadState,
  preToolChecks,
  promptSession,
  recordProgress,
  saveState,
  setLocation,
  shouldNudge,
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
const STATUS_CANCELLED = "01stcancelled000001"
const STATUSES = [
  { id: STATUS_BACKLOG, name: "Backlog", category: "backlog", position: 1 },
  { id: STATUS_PROGRESS, name: "In Progress", category: "in_progress", position: 2 },
  { id: STATUS_DONE, name: "Done", category: "done", position: 3 },
  { id: STATUS_CANCELLED, name: "Cancelled", category: "cancelled", position: 4 },
]

const PARENT_ISSUE = "01testparentissue00001"
const CHILD_ISSUE = "01testchildissue000001"
const OTHER_CHILD_ISSUE = "01testchildissue000002"

function state(overrides: Partial<IssueDevState> = {}): IssueDevState {
  return { ...freshState(WORKSPACE, WORKFLOW_ISSUE), ...overrides }
}

function expectedStatePath(directory: string): string {
  const hash = crypto.createHash("sha1").update(directory).digest("hex").slice(0, 12)
  return path.join(homedir(), ".cache", "opencode-issue-dev", `${hash}.json`)
}

// In-memory TaskR world speaking the MCP JSON-RPC wire protocol, mirroring
// the shapes the real server returns (status name only on parent/children).
type FakeIssue = { id: string; title: string; statusId: string; parentId: string | null }
type FakeTaskr = { issues: Record<string, FakeIssue>; moves: Array<{ issueId: string; statusId: string }> }

function installFakeTaskr(issues: FakeIssue[], options: { sse?: boolean } = {}): FakeTaskr {
  const world: FakeTaskr = { issues: {}, moves: [] }
  for (const issue of issues) world.issues[issue.id] = { ...issue }

  const statusName = (issueId: string): string | null => {
    const issue = world.issues[issueId]
    return STATUSES.find((candidate) => candidate.id === issue?.statusId)?.name ?? null
  }

  const detail = (issueId: string) => {
    const issue = world.issues[issueId]
    if (issue === undefined) return null
    const status = STATUSES.find((candidate) => candidate.id === issue.statusId)
    const parent = issue.parentId !== null ? world.issues[issue.parentId] : undefined
    const children = Object.values(world.issues).filter((candidate) => candidate.parentId === issueId)
    return {
      id: issue.id,
      title: issue.title,
      status: { id: status?.id ?? "", name: status?.name ?? "", category: status?.category ?? "" },
      parent: parent !== undefined ? { id: parent.id, title: parent.title, statusName: statusName(parent.id) } : null,
      children: children.map((child) => ({ id: child.id, title: child.title, statusName: statusName(child.id) })),
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

describe("state location", () => {
  test("state path is scoped to the location directory, not process.cwd()", () => {
    const directory = "/tmp/taskr-issue-dev-test"
    setLocation(directory)
    expect(statePath()).toBe(expectedStatePath(directory))
    expect(statePath()).not.toBe(expectedStatePath(process.cwd()))
  })

  test("a fresh workflow records its owning session and agent", () => {
    const current = freshState(WORKSPACE, WORKFLOW_ISSUE, "ses_owner", "orchestrator")
    expect(current.session_id).toBe("ses_owner")
    expect(current.agent).toBe("orchestrator")
    expect(current.done_blocked_notified).toBe(false)
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

  test("a comment on the workflow issue without a marker is refused", async () => {
    const current = state()
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "Note libre",
      }),
    ).rejects.toThrow(/marker line/)
  })

  test("a plan comment on the workflow issue is allowed once research is done", async () => {
    const current = state({ knowledge_searched: true, code_explored: true })
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        body: "<!-- taskr:plan -->\nPlan",
      }),
    ).resolves.toBeUndefined()
  })

  test("a plan comment on another issue is not treated as the workflow plan", async () => {
    const current = state({ knowledge_searched: true, code_explored: true })
    await expect(
      preToolChecks(current, "taskr_add_comment", {
        workspace_id: WORKSPACE,
        issue_id: OTHER_ISSUE,
        body: "<!-- taskr:plan -->\nPlan hors workflow",
      }),
    ).resolves.toBeUndefined()
  })
})

describe("time entry rules", () => {
  test("log_time on another issue is allowed before the done comment", async () => {
    const current = state({ plan_comment: true })
    await expect(
      preToolChecks(current, "taskr_log_time", {
        workspace_id: WORKSPACE,
        issue_id: OTHER_ISSUE,
        hours: 1,
      }),
    ).resolves.toBeUndefined()
  })

  test("log_time on the workflow issue before the done comment is refused", async () => {
    const current = state({ plan_comment: true })
    await expect(
      preToolChecks(current, "taskr_log_time", {
        workspace_id: WORKSPACE,
        issue_id: WORKFLOW_ISSUE,
        hours: 1,
      }),
    ).rejects.toThrow(/after the done comment/)
  })
})

describe("progress recording", () => {
  test("a comment on another issue does not flip the workflow flags", () => {
    const current = state({ knowledge_searched: true, code_explored: true })
    const changed = recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: OTHER_ISSUE, body: "<!-- taskr:plan -->\nPlan" },
      status: "completed",
    })
    expect(changed).toBe(false)
    expect(current.plan_comment).toBe(false)
  })

  test("a plan comment on the workflow issue flips the plan flag", () => {
    const current = state({ knowledge_searched: true, code_explored: true })
    const changed = recordProgress(current, "taskr_add_comment", {
      input: { workspace_id: WORKSPACE, issue_id: WORKFLOW_ISSUE, body: "<!-- taskr:plan -->\nPlan" },
      status: "completed",
    })
    expect(changed).toBe(true)
    expect(current.plan_comment).toBe(true)
  })

  test("a failed tool does not record progress", () => {
    const current = state()
    const changed = recordProgress(current, "taskr_search_knowledge", {
      input: { workspace_id: WORKSPACE, query: "digest" },
      status: "error",
    })
    expect(changed).toBe(false)
    expect(current.knowledge_searched).toBe(false)
  })
})

describe("workflow nudge", () => {
  test("the writer and reporter never receive the issue_dev nudge", () => {
    expect(shouldNudge("writer")).toBe(false)
    expect(shouldNudge("reporter")).toBe(false)
  })

  test("the orchestrator and work agents receive the nudge", () => {
    expect(shouldNudge("orchestrator")).toBe(true)
    expect(shouldNudge("dev")).toBe(true)
    expect(shouldNudge("")).toBe(true)
  })
})

describe("taskr rpc parsing", () => {
  test("parses plain JSON and SSE envelopes", () => {
    const json = extractRpcMessage('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}', "application/json")
    expect(json.result).toEqual({ ok: true })

    // Interleaved ping notifications must not shadow the actual response.
    const sse = [
      "event: message",
      'data: {"jsonrpc":"2.0","method":"notifications/ping"}',
      "",
      'data: {"jsonrpc":"2.0","id":2,"result":{"ok":true}}',
      "",
      "",
    ].join("\n")
    const parsed = extractRpcMessage(sse, "text/event-stream")
    expect(parsed.result).toEqual({ ok: true })
  })
})

describe("start status sync", () => {
  const directories: string[] = []

  function useTemporaryLocation(): void {
    const directory = path.join("/tmp", `taskr-issue-dev-test-${crypto.randomUUID()}`)
    directories.push(directory)
    setLocation(directory)
  }

  afterEach(() => {
    for (const directory of directories.splice(0)) {
      const file = expectedStatePath(directory)
      if (existsSync(file)) rmSync(file)
    }
    resetTaskr()
  })

  test("start moves a backlog issue to in progress and never touches an issue already in progress", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr([{ id: WORKFLOW_ISSUE, title: "The workflow issue", statusId: STATUS_BACKLOG, parentId: null }])

    const first = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })
    expect(first.ok).toBe(true)
    expect(first.message).toContain("moved to In Progress")
    expect(world.moves).toEqual([{ issueId: WORKFLOW_ISSUE, statusId: STATUS_PROGRESS }])
    expect(loadState().active).toBe(true)

    const second = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })
    expect(second.ok).toBe(true)
    expect(second.message).toContain("already in progress")
    expect(world.moves).toHaveLength(1)
  })

  test("start lifts a backlog parent when a child starts", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr([
      { id: PARENT_ISSUE, title: "Parent epic", statusId: STATUS_BACKLOG, parentId: null },
      { id: CHILD_ISSUE, title: "Child task", statusId: STATUS_BACKLOG, parentId: PARENT_ISSUE },
    ])

    const result = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: CHILD_ISSUE })
    expect(result.ok).toBe(true)
    expect(result.message).toContain("parent")
    expect(world.moves).toContainEqual({ issueId: CHILD_ISSUE, statusId: STATUS_PROGRESS })
    expect(world.moves).toContainEqual({ issueId: PARENT_ISSUE, statusId: STATUS_PROGRESS })
  })
})

describe("move_issue guard", () => {
  afterEach(resetTaskr)

  test("a parent cannot move to done while a child is open — the child is named", async () => {
    const world = installFakeTaskr([
      { id: PARENT_ISSUE, title: "Parent epic", statusId: STATUS_PROGRESS, parentId: null },
      { id: CHILD_ISSUE, title: "Blocking child", statusId: STATUS_PROGRESS, parentId: PARENT_ISSUE },
      { id: OTHER_CHILD_ISSUE, title: "Finished child", statusId: STATUS_DONE, parentId: PARENT_ISSUE },
    ])

    await expect(
      preToolChecks(state(), "taskr_move_issue", {
        workspace_id: WORKSPACE,
        issue_id: PARENT_ISSUE,
        status_id: STATUS_DONE,
      }),
    ).rejects.toThrow(/Blocking child \(01testchildissue000001/)
    expect(world.moves).toEqual([])
  })

  test("a parent moves to done when every child is closed (done or cancelled)", async () => {
    const world = installFakeTaskr([
      { id: PARENT_ISSUE, title: "Parent epic", statusId: STATUS_PROGRESS, parentId: null },
      { id: CHILD_ISSUE, title: "Done child", statusId: STATUS_DONE, parentId: PARENT_ISSUE },
      { id: OTHER_CHILD_ISSUE, title: "Cancelled child", statusId: STATUS_CANCELLED, parentId: PARENT_ISSUE },
    ])

    await expect(
      preToolChecks(state(), "taskr_move_issue", {
        workspace_id: WORKSPACE,
        issue_id: PARENT_ISSUE,
        status_id: STATUS_DONE,
      }),
    ).resolves.toBeUndefined()
    expect(world.moves).toEqual([])
  })

  test("moving a child to in progress lifts a backlog parent", async () => {
    const world = installFakeTaskr([
      { id: PARENT_ISSUE, title: "Parent epic", statusId: STATUS_BACKLOG, parentId: null },
      { id: CHILD_ISSUE, title: "Child task", statusId: STATUS_BACKLOG, parentId: PARENT_ISSUE },
    ])

    await expect(
      preToolChecks(state(), "taskr_move_issue", {
        workspace_id: WORKSPACE,
        issue_id: CHILD_ISSUE,
        status_id: STATUS_PROGRESS,
      }),
    ).resolves.toBeUndefined()
    expect(world.moves).toContainEqual({ issueId: PARENT_ISSUE, statusId: STATUS_PROGRESS })
  })
})

describe("turn gate", () => {
  const directories: string[] = []

  function useTemporaryLocation(): void {
    const directory = path.join("/tmp", `taskr-issue-dev-test-${crypto.randomUUID()}`)
    directories.push(directory)
    setLocation(directory)
  }

  afterEach(() => {
    for (const directory of directories.splice(0)) {
      const file = expectedStatePath(directory)
      if (existsSync(file)) rmSync(file)
    }
    resetTaskr()
  })

  test("skips sessions that do not own the workflow", async () => {
    useTemporaryLocation()
    saveState({ ...state(), session_id: "ses_owner" })
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, "ses_other")

    expect(calls).toEqual([])
  })

  test("prompts the session that opened the workflow", async () => {
    useTemporaryLocation()
    saveState({ ...state(), session_id: "ses_owner" })
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, "ses_owner")

    expect(calls).toHaveLength(1)
    expect((calls[0] as { text: string }).text).toContain("workflow is incomplete")
  })

  test("closes a workflow whose steps are all complete by moving the issue to done (SSE responses)", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr(
      [{ id: WORKFLOW_ISSUE, title: "The workflow issue", statusId: STATUS_PROGRESS, parentId: null }],
      { sse: true },
    )
    saveState({
      ...state({
        session_id: "ses_owner",
        knowledge_searched: true,
        code_explored: true,
        plan_comment: true,
        done_comment: true,
        time_logged: true,
        knowledge_reviewed: true,
      }),
    })
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, "ses_owner")

    expect(calls).toEqual([])
    expect(world.moves).toEqual([{ issueId: WORKFLOW_ISSUE, statusId: STATUS_DONE }])
    expect(loadState().active).toBe(false)
  })

  test("a parent with an open child is left in place, notified once, then closed", async () => {
    useTemporaryLocation()
    const world = installFakeTaskr([
      { id: PARENT_ISSUE, title: "Parent epic", statusId: STATUS_PROGRESS, parentId: null },
      { id: CHILD_ISSUE, title: "Blocking child", statusId: STATUS_PROGRESS, parentId: PARENT_ISSUE },
    ])
    saveState({
      ...state({
        session_id: "ses_owner",
        issue_id: PARENT_ISSUE,
        knowledge_searched: true,
        code_explored: true,
        plan_comment: true,
        done_comment: true,
        time_logged: true,
        knowledge_reviewed: true,
      }),
    })
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, "ses_owner")
    await turnGate(ctx, "ses_owner")

    expect(calls).toHaveLength(1)
    expect((calls[0] as { text: string }).text).toContain("Blocking child")
    expect(world.moves).toEqual([])
    expect(loadState().active).toBe(false)
    expect(loadState().done_blocked_notified).toBe(true)
  })
})

describe("taskr outage", () => {
  const directories: string[] = []

  function useTemporaryLocation(): void {
    const directory = path.join("/tmp", `taskr-issue-dev-test-${crypto.randomUUID()}`)
    directories.push(directory)
    setLocation(directory)
  }

  afterEach(() => {
    for (const directory of directories.splice(0)) {
      const file = expectedStatePath(directory)
      if (existsSync(file)) rmSync(file)
    }
    resetTaskr()
  })

  test("start succeeds and reports the outage", async () => {
    useTemporaryLocation()
    installUnreachableTaskr()

    const result = await cliMain({ action: "start", workspaceId: WORKSPACE, issueId: WORKFLOW_ISSUE })

    expect(result.ok).toBe(true)
    expect(result.message).toContain("unreachable")
    expect(loadState().active).toBe(true)
  })

  test("the turn gate still closes the workflow", async () => {
    useTemporaryLocation()
    installUnreachableTaskr()
    saveState({
      ...state({
        session_id: "ses_owner",
        knowledge_searched: true,
        code_explored: true,
        plan_comment: true,
        done_comment: true,
        time_logged: true,
        knowledge_reviewed: true,
      }),
    })
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, "ses_owner")

    expect(calls).toEqual([])
    expect(loadState().active).toBe(false)
  })

  test("move_issue is not blocked by the guard", async () => {
    useTemporaryLocation()
    installUnreachableTaskr()

    await expect(
      preToolChecks(state(), "taskr_move_issue", {
        workspace_id: WORKSPACE,
        issue_id: PARENT_ISSUE,
        status_id: STATUS_DONE,
      }),
    ).resolves.toBeUndefined()
  })
})

describe("session prompting", () => {
  test("uses the V2 ctx.session.prompt API when available", async () => {
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }
    await promptSession(ctx, "ses_1", "hello")
    expect(calls).toEqual([{ sessionID: "ses_1", text: "hello" }])
  })

  test("falls back to the V1 ctx.client.session.prompt API", async () => {
    const calls: unknown[] = []
    const ctx = { client: { session: { prompt: async (input: unknown) => calls.push(input) } } }
    await promptSession(ctx, "ses_2", "hello")
    expect(calls).toEqual([{ sessionID: "ses_2", text: "hello" }])
  })
})
