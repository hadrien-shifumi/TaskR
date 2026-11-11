// tdd-guard — red/green feedback loop for OpenCode.
//
// Three enforcement layers, mirroring the Vibe hooks:
//
// 1. tool.execute.before (bash): refuses E2E runs (`composer test:e2e`,
//    `--testsuite=Browser`, tests/Browser) while the dev suites are red or
//    stale — E2E is the final gate, never part of the dev loop.
//
// 2. tool.execute.after (edit/write on tests/ or source): runs the test
//    runner (Pest when the project ships it, PHPUnit otherwise) — the
//    edited file only when it is a test, the Unit+Feature suites when it is
//    source or test support code — and appends the verdict to the tool
//    result. Informational only: it never blocks an edit.
//
// 3. tool.execute.after (bash): watches manual test runs (`composer test`,
//    `artisan test`, phpunit/pest) and records the outcome so the turn gate
//    can judge the turn.
//
// 4. Turn gate (session.idle event): refuses to end an assistant turn while
//    the suites are red or a run is pending.
//
// Per-session state lives in a JSON file keyed by session ID. The TDD skill
// defines the workflow; this plugin supplies the feedback loop that makes it
// real.

import { execFile } from "node:child_process"
import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs"
import { homedir } from "node:os"
import path from "node:path"

const PHPUNIT_TIMEOUT_MS = 120_000
const MAX_OUTPUT_BYTES = 16 * 1024 * 1024

// phpunit.xml sets these with <env> only when they are absent, so any value
// inherited from the host process would silently override the testing profile
// — including DB_DATABASE. Strip them all: phpunit.xml is the single source
// of truth for test runs.
const LARAVEL_ENV_PREFIX =
  /^(APP_|DB_|SESSION_|CACHE_|QUEUE_|MAIL_|BROADCAST_|REDIS_|MEMCACHED_|FILESYSTEM_|AWS_|LOG_|BCRYPT_|VITE_|TELESCOPE_|PULSE_|NIGHTWATCH_)/

function sanitizedEnv(): NodeJS.ProcessEnv {
  const env: NodeJS.ProcessEnv = {}
  for (const [key, value] of Object.entries(process.env)) {
    if (!LARAVEL_ENV_PREFIX.test(key)) env[key] = value
  }
  return env
}

const TEST_FILE = /(^|\/)tests\/.*Test\.php$/
const TEST_DIR = /(^|\/)tests\//
const SOURCE_FILE = /(^|\/)(app|routes|database|config|resources\/js|resources\/views)\//

// Dev-loop scope: Unit + Feature. E2E (Browser) is excluded from every
// automatic run — it is the final gate, not the feedback loop.
const DEV_SUITES = "Unit,Feature"

// ---------------------------------------------------------------------------
// Per-session state (mirrors .vibe/hooks/tdd-guard.php + tdd-gate.php)
// ---------------------------------------------------------------------------

type Phase = "green" | "red" | "regression" | "unknown"

export type TddState = {
  phase: Phase
  phase_at: number
  dirty: boolean
  e2e_at?: number
}

function stateDir(): string {
  return path.join(homedir(), ".cache", "opencode-tdd")
}

function statePath(sessionId: string): string {
  const safe = sessionId.replace(/[^A-Za-z0-9_-]/g, "")
  return path.join(stateDir(), `${safe || "default"}.json`)
}

export function loadState(sessionId: string): TddState {
  try {
    const raw = readFileSync(statePath(sessionId), "utf-8")
    const parsed = JSON.parse(raw)
    return {
      phase: parsed.phase ?? "unknown",
      phase_at: parsed.phase_at ?? 0,
      dirty: parsed.dirty ?? false,
      e2e_at: parsed.e2e_at,
    }
  } catch {
    return { phase: "unknown", phase_at: 0, dirty: false }
  }
}

export function saveState(sessionId: string, state: TddState): void {
  try {
    mkdirSync(stateDir(), { recursive: true })
    writeFileSync(statePath(sessionId), JSON.stringify(state))
  } catch {
    // State persistence must never break the tool flow.
  }
}

function recordVerdict(state: TddState, phase: Phase, clearDirty: boolean): void {
  state.phase = phase
  state.phase_at = Date.now()
  if (clearDirty) state.dirty = false
}

// ---------------------------------------------------------------------------
// Test runner (Pest when present, PHPUnit otherwise)
// ---------------------------------------------------------------------------

let projectRoot: string | null = null
let chain: Promise<unknown> = Promise.resolve()

export function findRoot(start: string): string | null {
  let dir = path.dirname(start)
  for (let level = 0; level < 10; level += 1) {
    if (hasRunner(dir)) return dir
    const parent = path.dirname(dir)
    if (parent === dir) return null
    dir = parent
  }
  return null
}

function hasRunner(dir: string): boolean {
  return existsSync(path.join(dir, "vendor", "bin", "phpunit")) || existsSync(path.join(dir, "vendor", "bin", "pest"))
}

// Pest projects ship vendor/bin/phpunit, but running it directly dies with
// "Pest must be run through its own binary" — so pest wins whenever present.
export function runnerPath(root: string): string {
  const pest = path.join(root, "vendor", "bin", "pest")
  if (existsSync(pest)) return pest
  return path.join(root, "vendor", "bin", "phpunit")
}

// The suites exhaust PHP's default 128M on full Unit+Feature runs; the
// project rules prescribe 2G. Override with TDD_PHP_MEMORY_LIMIT.
const PHP_MEMORY_LIMIT = process.env.TDD_PHP_MEMORY_LIMIT ?? "2G"

export function runnerArgs(args: string[]): string[] {
  return ["-d", `memory_limit=${PHP_MEMORY_LIMIT}`, ...args]
}

// Serialized: burst edits share the runner, each verdict reflects the state
// after the previous edit landed.
function runSuite(root: string, args: string[]): Promise<{ code: number; out: string }> {
  const task = chain.then(
    () =>
      new Promise<{ code: number; out: string }>((resolve) => {
        execFile(
          runnerPath(root),
          runnerArgs(args),
          { cwd: root, timeout: PHPUNIT_TIMEOUT_MS, maxBuffer: MAX_OUTPUT_BYTES, env: sanitizedEnv() },
          (error, stdout, stderr) => {
            const code = error ? (typeof error.code === "number" ? error.code : 1) : 0
            resolve({ code, out: `${stdout}\n${stderr}`.trim() })
          },
        )
      }),
  )
  chain = task.catch(() => undefined)
  return task
}

// ---------------------------------------------------------------------------
// Verdict summarization (mirrors summarize() in tdd-guard.php)
// ---------------------------------------------------------------------------

type Verdict = { state: "green" | "red" | "empty" | "error"; summary: string; failures: string[] }

function firstLine(text: string, re: RegExp): string | null {
  const m = text.match(re)
  return m ? m[0].trim() : null
}

// Tolerates a machine-readable one-liner some output layers emit for phpunit,
// pest and `artisan test --compact`:
// {"tool":"phpunit|pest|artisan test","result":"passed"|"failed","tests":N,"failed":M,...}
function fromRunnerJson(out: string): Verdict | null {
  const m = out.match(/^\s*\{"tool":"(?:phpunit|pest|artisan test)".*\}\s*$/s)
  if (!m) return null
  try {
    const j = JSON.parse(m[0]) as Record<string, any>
    const count = `${j.tests ?? "?"} tests, ${j.assertions ?? "?"} assertions`
    if (j.result === "passed") return { state: "green", summary: count, failures: [] }
    const names = [...(j.failures ?? []), ...(j.error_details ?? [])]
      .map((f: any) => String(f?.test ?? ""))
      .filter(Boolean)
      .slice(0, 3)
    return { state: "red", summary: `${count}, ${j.failed ?? 0} failed`, failures: names }
  } catch {
    return null
  }
}

export function summarize(code: number, out: string): Verdict {
  // Strip ANSI color codes (phpunit.xml sets colors="true")
  const clean = out.replace(/\x1b\[[0-9;?]*[a-zA-Z]/g, "")
  const json = fromRunnerJson(clean)
  if (json) return json
  if (code === 0) {
    return { state: "green", summary: firstLine(clean, /^OK\b.*$/m) ?? "all tests passed", failures: [] }
  }
  if (code === 124) {
    return { state: "error", summary: `test run exceeded ${PHPUNIT_TIMEOUT_MS / 1000}s and was killed`, failures: [] }
  }
  if (/No tests (?:found|executed)/.test(clean)) {
    return { state: "empty", summary: "no tests executed in this scope", failures: [] }
  }
  const fatal = firstLine(clean, /^.*PHP (?:Parse|Fatal) error:.*$/m)
  if (fatal) return { state: "error", summary: fatal, failures: [] }
  return {
    state: "red",
    summary: firstLine(clean, /^Tests:.*$/m) ?? `exit code ${code}`,
    // PHPUnit lists failures as "1) Tests\…", Pest as "FAILED  Tests\…".
    failures: [...clean.matchAll(/^(?:\d+\)\s+|FAILED\s+)(\S+)/gm)].map((m) => m[1]).slice(0, 3),
  }
}

// ---------------------------------------------------------------------------
// Output injection (appends a verdict block to the tool result)
// ---------------------------------------------------------------------------

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
      if (r.metadata && typeof r.metadata === "object") r.metadata.tddGuard = text
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
  return ["── tdd-guard ──", ...lines, "───────────────"].join("\n")
}

// V2 tool results carry their output as content parts; V1 exposed a plain
// `output` string. Read both so the bash watcher can parse a test run.
export function resultText(result: unknown): string {
  if (!result || typeof result !== "object") return ""
  const r = result as Record<string, any>
  if (typeof r.output === "string") return r.output
  if (typeof r.text === "string") return r.text
  if (Array.isArray(r.content)) {
    return r.content
      .filter((part: any) => part && part.type === "text" && typeof part.text === "string")
      .map((part: any) => part.text)
      .join("\n")
  }
  return ""
}

// ---------------------------------------------------------------------------
// Command classification (mirrors tdd-guard.php)
// ---------------------------------------------------------------------------

export function isE2eCommand(command: string): boolean {
  return /composer\s+(?:run\s+)?test:e2e|--testsuite(?:=|\s+)Browser|tests\/Browser\/|artisan\s+test\b[^\n]*Browser/.test(command)
}

export function isDevTestCommand(command: string): boolean {
  return /vendor\/bin\/(?:phpunit|pest)|composer\s+(?:run\s+)?test\b|artisan\s+test\b/.test(command)
}

// A narrow run targets one file or filter and cannot clear a suite verdict.
export function isNarrowRun(command: string): boolean {
  return /--filter\b|tests\/\S+|artisan\s+test\s+--compact\s+\S/.test(command)
}

// ---------------------------------------------------------------------------
// Record bash test runs (mirrors recordBashRun in tdd-guard.php)
// ---------------------------------------------------------------------------

export function recordBashRun(state: TddState, command: string, outputText: string, toolFailed: boolean): void {
  if (isE2eCommand(command)) {
    state.e2e_at = Date.now()
    return
  }
  if (!isDevTestCommand(command)) return

  const verdict = summarize(toolFailed ? 1 : 0, outputText)
  if (verdict.state === "empty" || verdict.state === "error") return

  if (isNarrowRun(command)) {
    // Narrow runs cannot prove the suites: green changes nothing, red is red.
    if (verdict.state === "red") recordVerdict(state, "red", false)
    return
  }

  recordVerdict(state, verdict.state === "green" ? "green" : "regression", true)
}

// ---------------------------------------------------------------------------
// Guard logic (edit/write → run tests)
// ---------------------------------------------------------------------------

function testFileLines(verdict: Verdict, rel: string, isNewFile: boolean): string[] {
  const lines: string[] = []
  if (verdict.state === "green") {
    lines.push(`🟢 GREEN — ${rel} (${verdict.summary})`)
    if (isNewFile) {
      lines.push("⚠️ brand-new test passed immediately: the behavior already exists, or the test asserts nothing. A new TDD test must fail first.")
    }
  } else if (verdict.state === "empty") {
    lines.push(`⚪ ${rel}: ${verdict.summary} — empty skeleton?`)
  } else if (verdict.state === "error") {
    lines.push(`⛔ ${rel}: ${verdict.summary}`)
  } else {
    lines.push(`🔴 RED — ${rel} (${verdict.summary})`)
    lines.push(...verdict.failures.map((f) => `   ✗ ${f}`))
    lines.push("Expected while the behavior does not exist yet — write the minimal implementation that turns this green.")
  }
  return lines
}

function suiteLines(verdict: Verdict): string[] {
  const lines: string[] = []
  if (verdict.state === "green") {
    lines.push(`🟢 Unit+Feature GREEN (${verdict.summary})`)
    lines.push("Browser (E2E) excluded from the dev loop — run `composer test:e2e` once at the final gate.")
  } else if (verdict.state === "red") {
    lines.push(`🔴 Unit+Feature RED — ${verdict.summary}`)
    lines.push(...verdict.failures.map((f) => `   ✗ ${f}`))
    lines.push("The task is not done while this is red.")
  } else if (verdict.state === "error") {
    lines.push(`⛔ Unit+Feature cannot run: ${verdict.summary}`)
  } else {
    lines.push(`⚪ ${verdict.summary}`)
  }
  return lines
}

export async function guardEditWrite(
  tool: string,
  args: Record<string, unknown> | undefined,
  result: unknown,
  sessionId: string,
): Promise<void> {
  if (tool !== "edit" && tool !== "write") return
  const raw = args?.path ?? args?.filePath ?? args?.file_path
  if (typeof raw !== "string" || raw === "") return

  const norm = raw.replace(/\\/g, "/")
  const isTestFile = TEST_FILE.test(norm)
  if (!isTestFile && !TEST_DIR.test(norm) && !SOURCE_FILE.test(norm)) return

  const root = (projectRoot ??= findRoot(raw))
  if (!root) return

  const rel = path.relative(root, raw).replace(/\\/g, "/")
  const state = loadState(sessionId)
  state.dirty = true
  const isNewFile = tool === "write"

  if (isTestFile) {
    // Browser (E2E) files are excluded from the dev loop by design.
    if (/(^|\/)tests\/Browser\//.test(norm)) {
      saveState(sessionId, state)
      inject(result, block([
        `🌐 Browser (E2E) file ${rel} — excluded from the dev loop; covered by \`composer test:e2e\` at the final gate.`,
      ]))
      return
    }

    const { code, out } = await runSuite(root, [raw])
    const verdict = summarize(code, out)
    // A green single file only clears the red-test phase, never a suite regression.
    if (verdict.state === "red") {
      recordVerdict(state, "red", false)
    } else if (verdict.state === "green" && state.phase !== "regression") {
      recordVerdict(state, "green", true)
    }
    const lines = testFileLines(verdict, rel, isNewFile)
    if (state.phase === "regression" && verdict.state !== "green") {
      lines.push("⚠️ the Unit+Feature suites were RED at their last run — fix the code rather than adjusting the test, unless the test itself is wrong.")
    }
    saveState(sessionId, state)
    inject(result, block(lines))
  } else {
    const { code, out } = await runSuite(root, ["--testsuite", DEV_SUITES])
    const verdict = summarize(code, out)
    recordVerdict(
      state,
      verdict.state === "green" ? "green" : verdict.state === "red" ? "regression" : state.phase,
      verdict.state === "green",
    )
    saveState(sessionId, state)
    inject(result, block(suiteLines(verdict)))
  }
}

// ---------------------------------------------------------------------------
// Pre-tool E2E gate (mirrors the pre_tool branch in tdd-guard.php)
// ---------------------------------------------------------------------------

export function guardBashBefore(tool: string, args: Record<string, unknown> | undefined, sessionId: string): void {
  if (!tool.includes("bash")) return
  const command = String(args?.command ?? "")
  if (!isE2eCommand(command)) return

  const state = loadState(sessionId)
  if (state.phase === "red" || state.phase === "regression" || state.dirty) {
    throw new Error(
      "tdd-e2e-gate: E2E is the final gate — the Unit+Feature suites are red or stale. " +
        "Bring `composer test` to green first, then run `composer test:e2e` once.",
    )
  }
}

// ---------------------------------------------------------------------------
// Post-tool bash watcher (records manual test runs)
// ---------------------------------------------------------------------------

export function guardBashAfter(
  tool: string,
  args: Record<string, unknown> | undefined,
  event: any,
  sessionId: string,
): void {
  if (!tool.includes("bash")) return
  const command = String(args?.command ?? "")
  const outputText = resultText(event?.result) || String(event?.output ?? "")
  // V2 reports failures through status; V1 used an error flag.
  const toolFailed =
    event?.status === "error" || event?.result?.metadata?.error === true || event?.error === true
  const state = loadState(sessionId)
  recordBashRun(state, command, outputText, toolFailed)
  saveState(sessionId, state)
}

// ---------------------------------------------------------------------------
// Turn gate (mirrors tdd-gate.php)
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

export async function turnGate(ctx: any, sessionId: string): Promise<void> {
  const state = loadState(sessionId)
  const phase = state.phase

  if (phase === "regression") {
    await promptSession(
      ctx,
      sessionId,
      "tdd-gate: Unit+Feature are RED at their last run — the task is not done. Continue the implementation pass and rerun `composer test` until green.",
    )
    return
  }

  if (phase === "red") {
    await promptSession(
      ctx,
      sessionId,
      "tdd-gate: tests were written and are RED (normal TDD phase), but nothing is green yet. Do the minimal implementation pass and rerun the tests. Only stop at red if the user explicitly asked for the test plan alone — in that case, restate that and stop.",
    )
    return
  }

  if (state.dirty) {
    await promptSession(
      ctx,
      sessionId,
      "tdd-gate: code or tests were edited since the last test run. Rerun the covering scope (`composer test` or the narrowest test file) before finishing this turn.",
    )
  }
}

// ---------------------------------------------------------------------------
// Plugin entrypoints
// ---------------------------------------------------------------------------

// OpenCode v1 entrypoint: server(ctx) -> hooks.
export const TddGuardPlugin = async (ctx: any): Promise<
  Record<string, (input: any, output: any) => Promise<void>>
> => ({
  "tool.execute.before": async (input, output) => {
    const sessionId = String(input?.sessionID ?? input?.session_id ?? "default")
    guardBashBefore(String(input?.tool ?? ""), input?.args ?? output?.args, sessionId)
  },
  "tool.execute.after": async (input, output) => {
    const tool = String(input?.tool ?? "")
    const sessionId = String(input?.sessionID ?? input?.session_id ?? "default")
    const args = input?.args ?? {}
    if (tool.includes("bash")) {
      guardBashAfter(tool, args, output, sessionId)
      return
    }
    await guardEditWrite(tool, args, output, sessionId)
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
})

// OpenCode v2 entrypoint.
const setup = async (ctx: any) => {
  // Pre-tool: block E2E while the dev loop is red or stale.
  await ctx.tool.hook("execute.before", async (event: any) => {
    const sessionId = String(event?.sessionID ?? event?.session_id ?? "default")
    guardBashBefore(String(event?.tool ?? ""), event?.input ?? event?.args ?? {}, sessionId)
  })

  // Post-tool: run tests after edits, watch bash test runs.
  await ctx.tool.hook("execute.after", async (event: any) => {
    const tool = String(event?.tool ?? "")
    const sessionId = String(event?.sessionID ?? event?.session_id ?? "default")
    const args = event?.input ?? event?.args ?? {}
    if (tool.includes("bash")) {
      guardBashAfter(tool, args, event, sessionId)
      return
    }
    await guardEditWrite(tool, args, event, sessionId)
  })

  // Turn gate: refuse to end the turn while the TDD loop is open.
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

export default { id: "tdd-guard", server: TddGuardPlugin, setup }
