// taskr — minimal MCP JSON-RPC client for the TaskR server.
//
// The issue-dev plugin keeps the tracker statuses coherent with the
// workflow (In Progress on start, Done at closure, parent/child rules).
// It talks to the same remote MCP server the agent uses, over plain
// fetch: initialize once per client, then tools/call with JSON or SSE
// responses, inside a single ~8 s budget per operation.
//
// Every failure degrades to null plus a stderr line: an unreachable or
// misbehaving TaskR never breaks the workflow, it only skips the status
// sync. Tests replace the transport (setTaskrTransport) and the server
// config (setTaskrConfig) so no test ever touches the network.

import { readFileSync } from "node:fs"
import path from "node:path"

const PROTOCOL_VERSION = "2026-07-28"
const REQUEST_TIMEOUT_MS = 8_000

export type TaskrConfig = { url: string; headers: Record<string, string> }

export type TaskrStatus = { id: string; name: string; category: string; position: number }

export type TaskrIssueRef = { id: string; title: string; statusName: string | null }

export type TaskrIssue = {
  id: string
  title: string
  status: { id: string; name: string; category: string }
  parent: TaskrIssueRef | null
  children: TaskrIssueRef[]
}

// ---------------------------------------------------------------------------
// Transport injection — tests install an in-memory fake, production uses fetch
// ---------------------------------------------------------------------------

export type TaskrTransportRequest = {
  method: string
  headers: Record<string, string>
  body: string
  signal: AbortSignal
}

export type TaskrTransportResponse = { status: number; headers: Record<string, string>; body: string }

export type TaskrTransport = (url: string, request: TaskrTransportRequest) => Promise<TaskrTransportResponse>

let transport: TaskrTransport | null = null
let injectedConfig: TaskrConfig | null = null
// Session ids are cached per server URL so one initialize serves every
// client; stateless servers simply never populate the cache.
const sessionIds = new Map<string, string>()

export function setTaskrTransport(fake: TaskrTransport | null): void {
  transport = fake
  sessionIds.clear()
}

export function resetTaskrTransport(): void {
  setTaskrTransport(null)
}

export function setTaskrConfig(config: TaskrConfig | null): void {
  injectedConfig = config
  sessionIds.clear()
}

function activeTransport(): TaskrTransport {
  if (transport !== null) return transport
  return async (url, request) => {
    const response = await fetch(url, {
      method: request.method,
      headers: request.headers,
      body: request.body,
      signal: request.signal,
    })
    const headers: Record<string, string> = {}
    response.headers.forEach((value: string, key: string) => {
      headers[key.toLowerCase()] = value
    })
    return { status: response.status, headers, body: await response.text() }
  }
}

// ---------------------------------------------------------------------------
// Server config — ctx.mcp.list() first, opencode.json as the V1 fallback
// ---------------------------------------------------------------------------

// Substitutes {env:VAR} placeholders (opencode.json syntax) from the
// current environment; unknown variables resolve to an empty string.
function substituteEnv(value: string): string {
  return value.replace(/\{env:([A-Za-z_][A-Za-z0-9_]*)\}/g, (_, name) => process.env[name] ?? "")
}

function normalizeHeaders(raw: unknown): Record<string, string> {
  const headers: Record<string, string> = {}
  if (raw !== null && typeof raw === "object") {
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
      if (typeof value === "string") headers[key] = substituteEnv(value)
    }
  }
  return headers
}

// OpenCode v2 exposes the MCP server configs on ctx.mcp.list(). The result
// may be an array of configs or a record keyed by server id; match the
// taskr entry tolerantly.
async function configFromCtx(ctx: any): Promise<TaskrConfig | null> {
  try {
    const list = ctx?.mcp?.list
    if (typeof list !== "function") return null
    const servers = await Promise.resolve(list.call(ctx.mcp))
    const entries = Array.isArray(servers)
      ? servers
      : servers !== null && typeof servers === "object"
        ? Object.values(servers)
        : []
    for (const entry of entries) {
      if (entry === null || typeof entry !== "object") continue
      if (String(entry.id ?? entry.name ?? "") !== "taskr") continue
      if (typeof entry.url === "string" && entry.url !== "") {
        return { url: entry.url, headers: normalizeHeaders(entry.headers) }
      }
    }
  } catch {
    // A failing ctx must not shadow the opencode.json fallback.
  }
  return null
}

function configFromFile(directory: string): TaskrConfig | null {
  try {
    const parsed = JSON.parse(readFileSync(path.join(directory, "opencode.json"), "utf-8"))
    const server = parsed?.mcp?.servers?.taskr
    if (server !== null && typeof server === "object" && typeof server.url === "string" && server.url !== "") {
      return { url: server.url, headers: normalizeHeaders(server.headers) }
    }
  } catch {
    // No or unreadable config file: the server is simply unreachable.
  }
  return null
}

async function resolveConfig(ctx?: any): Promise<TaskrConfig | null> {
  if (injectedConfig !== null) return injectedConfig

  const fromCtx = await configFromCtx(ctx)
  if (fromCtx !== null) return fromCtx

  const directory = ctx?.location?.directory ?? ctx?.directory ?? ctx?.cwd
  return configFromFile(typeof directory === "string" && directory !== "" ? directory : process.cwd())
}

// ---------------------------------------------------------------------------
// JSON-RPC plumbing
// ---------------------------------------------------------------------------

function logFailure(context: string, detail: unknown): void {
  const message = detail instanceof Error ? detail.message : String(detail)
  process.stderr.write(`issue-dev taskr: ${context} — ${message}\n`)
}

function truncate(text: string): string {
  return text.length > 200 ? `${text.slice(0, 200)}…` : text
}

function headerValue(headers: Record<string, string>, name: string): string | null {
  const lower = name.toLowerCase()
  for (const [key, value] of Object.entries(headers)) {
    if (key.toLowerCase() === lower) return value
  }
  return null
}

// Extracts the JSON-RPC message from a response body: plain JSON, or SSE
// where only `data:` lines carry messages. Streamed answers may interleave
// notifications (ping): keep the message that carries a result or an error.
export function extractRpcMessage(body: string, contentType: string): any | null {
  const parse = (text: string): any | null => {
    try {
      const parsed = JSON.parse(text)
      return parsed !== null && typeof parsed === "object" ? parsed : null
    } catch {
      return null
    }
  }

  if (contentType.includes("text/event-stream")) {
    let response: any = null
    for (const line of body.split(/\r?\n/)) {
      if (!line.startsWith("data:")) continue
      const message = parse(line.slice(5).trim())
      if (message === null) continue
      if (message.result !== undefined || message.error !== undefined) response = message
      else if (response === null) response = message
    }
    return response
  }

  return parse(body)
}

// Returns the structured payload of a tools/call result, or null when the
// call failed at any layer (transport, rpc error, isError tool result).
function unwrapToolResult(message: any): any | null {
  if (message === null || typeof message !== "object") return null
  if (message.error !== undefined) {
    logFailure("rpc error", JSON.stringify(message.error))
    return null
  }
  const result = message.result
  if (result === null || typeof result !== "object") return null
  if (result.isError === true) {
    logFailure("tool call rejected", JSON.stringify(result.content ?? null))
    return null
  }
  if (result.structuredContent !== null && typeof result.structuredContent === "object") {
    return result.structuredContent
  }
  // Servers without structuredContent mirror the payload as a JSON text block.
  const content = Array.isArray(result.content) ? result.content : []
  for (const part of content) {
    if (part === null || typeof part !== "object" || typeof part.text !== "string") continue
    const parsed = parseObject(part.text)
    if (parsed !== null) return parsed
  }
  return null
}

function parseObject(text: string): any | null {
  try {
    const parsed = JSON.parse(text)
    return parsed !== null && typeof parsed === "object" ? parsed : null
  } catch {
    return null
  }
}

// ---------------------------------------------------------------------------
// Client — one instance per plugin operation, one initialize per instance
// ---------------------------------------------------------------------------

export type TaskrClient = {
  listStatuses(workspaceId: string): Promise<TaskrStatus[] | null>
  getIssue(workspaceId: string, issueId: string): Promise<TaskrIssue | null>
  moveIssue(workspaceId: string, issueId: string, statusId: string): Promise<TaskrIssue | null>
}

// The tool argument names match the TaskR server schemas exactly:
// list_statuses/get_issue/move_issue all key on workspace_id, and the
// issue-targeted tools add issue_id (move_issue: status_id).
export async function createTaskrClient(ctx?: any): Promise<TaskrClient | null> {
  const config = await resolveConfig(ctx)
  if (config === null) {
    logFailure("no server config", "neither ctx.mcp.list() nor opencode.json exposes the taskr server")
    return null
  }

  // One 8 s budget for the whole operation: initialize plus a handful of
  // tool calls must never hold a tool check hostage for long.
  const deadline = Date.now() + REQUEST_TIMEOUT_MS
  let nextId = 1

  const send = async (rpc: Record<string, unknown>, notification: boolean): Promise<any | null> => {
    const remaining = deadline - Date.now()
    if (remaining <= 0) return null
    try {
      const headers: Record<string, string> = {
        "Content-Type": "application/json",
        Accept: "application/json, text/event-stream",
        ...config.headers,
      }
      const session = sessionIds.get(config.url)
      if (session !== undefined) headers["Mcp-Session-Id"] = session

      const response = await activeTransport()(config.url, {
        method: "POST",
        headers,
        body: JSON.stringify(rpc),
        signal: AbortSignal.timeout(Math.min(REQUEST_TIMEOUT_MS, remaining)),
      })

      if (response.status >= 400) {
        logFailure(`http ${response.status} on ${String(rpc.method)}`, truncate(response.body))
        // 404 is how a streamable server reports an expired session.
        if (response.status === 404) sessionIds.delete(config.url)
        return null
      }

      const newSession = headerValue(response.headers, "Mcp-Session-Id")
      if (newSession !== null && newSession !== "") sessionIds.set(config.url, newSession)

      if (notification) return null
      const contentType = headerValue(response.headers, "Content-Type") ?? ""
      const message = extractRpcMessage(response.body, contentType)
      if (message === null) {
        logFailure(`unparsable response on ${String(rpc.method)}`, truncate(response.body))
      }
      return message
    } catch (error) {
      logFailure(`${String(rpc.method)} failed`, error)
      return null
    }
  }

  let ready: Promise<boolean> | null = null
  const initialize = (): Promise<boolean> => {
    ready ??= (async () => {
      const message = await send(
        {
          jsonrpc: "2.0",
          id: nextId++,
          method: "initialize",
          params: {
            protocolVersion: PROTOCOL_VERSION,
            capabilities: {},
            clientInfo: { name: "issue-dev", version: "1.0.0" },
          },
        },
        false,
      )
      if (message === null || message.error !== undefined) return false
      await send({ jsonrpc: "2.0", method: "notifications/initialized", params: {} }, true)
      return true
    })()
    return ready
  }

  const callTool = async (name: string, args: Record<string, unknown>): Promise<any | null> => {
    if (!(await initialize())) return null
    const message = await send(
      { jsonrpc: "2.0", id: nextId++, method: "tools/call", params: { name, arguments: args } },
      false,
    )
    return unwrapToolResult(message)
  }

  const issueFrom = (payload: any): TaskrIssue | null => {
    return payload !== null && typeof payload.issue === "object" && payload.issue !== null ? payload.issue : null
  }

  return {
    async listStatuses(workspaceId) {
      const payload = await callTool("list_statuses", { workspace_id: workspaceId })
      return Array.isArray(payload?.statuses) ? payload.statuses : null
    },
    async getIssue(workspaceId, issueId) {
      return issueFrom(await callTool("get_issue", { workspace_id: workspaceId, issue_id: issueId }))
    },
    async moveIssue(workspaceId, issueId, statusId) {
      return issueFrom(
        await callTool("move_issue", { workspace_id: workspaceId, issue_id: issueId, status_id: statusId }),
      )
    },
  }
}
