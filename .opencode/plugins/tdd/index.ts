// tdd-guard — keeps the agent honest about red/green.
//
// After every edit/write on a test file (*Test.php under tests/), runs that
// file. After every edit/write on source (app/, routes/, database/, config/,
// resources/js, resources/views) or on tests/ support files, runs the Unit +
// Feature suites only — the Browser suite (E2E) is too slow for the dev loop
// and belongs to the final gate (`composer test:e2e`), matching the composer
// `test` / `test:e2e` scripts. Appends the verdict to the tool result.
//
// Informational only: it never blocks an edit. The TDD skill defines the
// workflow; this plugin supplies the feedback loop that makes it real.

import { execFile } from "node:child_process"
import { existsSync } from "node:fs"
import path from "node:path"

const PHPUNIT_TIMEOUT_MS = 120_000
const MAX_OUTPUT_BYTES = 16 * 1024 * 1024

// phpunit.xml sets these with <env> only when they are absent, so any value
// inherited from the host process (e.g. Bun auto-loading the project .env)
// would silently override the testing profile — including DB_DATABASE. Strip
// them all: phpunit.xml is the single source of truth for test runs.
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

let projectRoot: string | null = null
let chain: Promise<unknown> = Promise.resolve()
let lastDevSuiteStatus: "green" | "red" | "unknown" = "unknown"

function findRoot(start: string): string | null {
  let dir = path.dirname(start)
  for (let level = 0; level < 10; level += 1) {
    if (existsSync(path.join(dir, "vendor", "bin", "phpunit"))) return dir
    const parent = path.dirname(dir)
    if (parent === dir) return null
    dir = parent
  }
  return null
}

// Serialized: burst edits share the runner, each verdict reflects the state
// after the previous edit landed.
function phpunit(root: string, args: string[]): Promise<{ code: number; out: string }> {
  const task = chain.then(
    () =>
      new Promise<{ code: number; out: string }>((resolve) => {
        execFile(
          path.join(root, "vendor", "bin", "phpunit"),
          args,
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

type Verdict = { state: "green" | "red" | "empty" | "error"; summary: string; failures: string[] }

function firstLine(text: string, re: RegExp): string | null {
  const m = text.match(re)
  return m ? m[0].trim() : null
}

// Tolerates a machine-readable one-liner some output layers emit for phpunit:
// {"tool":"phpunit","result":"passed"|"failed","tests":N,"failed":M,...}
function fromRunnerJson(out: string): Verdict | null {
  const m = out.match(/^\s*\{"tool":"(?:phpunit|artisan test)".*\}\s*$/s)
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
  const json = fromRunnerJson(out)
  if (json) return json
  if (code === 0) {
    return { state: "green", summary: firstLine(out, /^OK\b.*$/m) ?? "all tests passed", failures: [] }
  }
  if (/No tests (?:found|executed)/.test(out)) {
    return { state: "empty", summary: "no tests executed in this scope", failures: [] }
  }
  const fatal = firstLine(out, /^.*PHP (?:Parse|Fatal) error:.*$/m)
  if (fatal) return { state: "error", summary: fatal, failures: [] }
  return {
    state: "red",
    summary: firstLine(out, /^Tests:.*$/m) ?? `exit code ${code}`,
    failures: [...out.matchAll(/^\d+\)\s+\S+/gm)].map((m) => m[0].replace(/^\d+\)\s+/, "")).slice(0, 3),
  }
}

export function inject(target: unknown, text: string): void {
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

async function guard(tool: string, args: Record<string, unknown> | undefined, result: unknown): Promise<void> {
  if (tool !== "edit" && tool !== "write") return
  const raw = args?.path ?? args?.filePath ?? args?.file_path
  if (typeof raw !== "string" || raw === "") return

  const norm = raw.replace(/\\/g, "/")
  const isTestFile = TEST_FILE.test(norm)
  if (!isTestFile && !TEST_DIR.test(norm) && !SOURCE_FILE.test(norm)) return

  const root = (projectRoot ??= findRoot(raw))
  if (!root) return

  const rel = path.relative(root, raw).replace(/\\/g, "/")
  const lines: string[] = []

  if (isTestFile) {
    const { code, out } = await phpunit(root, [raw])
    const v = summarize(code, out)
    if (v.state === "green") {
      lines.push(`🟢 GREEN — ${rel} (${v.summary})`)
      if (tool === "write") {
        lines.push(
          "⚠️ brand-new test passed immediately: the behavior already exists, or the test asserts nothing. A new TDD test must fail first.",
        )
      }
    } else if (v.state === "empty") {
      lines.push(`⚪ ${rel}: ${v.summary} — empty skeleton?`)
    } else if (v.state === "error") {
      lines.push(`⛔ ${rel}: ${v.summary}`)
    } else {
      lines.push(`🔴 RED — ${rel} (${v.summary})`)
      lines.push(...v.failures.map((f) => `   ✗ ${f}`))
      lines.push("Expected while the behavior does not exist yet — write the minimal implementation that turns this green.")
    }
    if (lastDevSuiteStatus === "red" && v.state !== "green") {
      lines.push("⚠️ the Unit+Feature suites were RED at their last run — fix the code rather than adjusting the test, unless the test itself is wrong.")
    }
  } else {
    const { code, out } = await phpunit(root, ["--testsuite", DEV_SUITES])
    const v = summarize(code, out)
    if (v.state === "green") {
      lastDevSuiteStatus = "green"
      lines.push(`🟢 Unit+Feature GREEN (${v.summary})`)
      lines.push("Browser (E2E) excluded from the dev loop — run `composer test:e2e` once at the final gate.")
    } else if (v.state === "red") {
      lastDevSuiteStatus = "red"
      lines.push(`🔴 Unit+Feature RED — ${v.summary}`)
      lines.push(...v.failures.map((f) => `   ✗ ${f}`))
      lines.push("The task is not done while this is red.")
    } else if (v.state === "error") {
      lines.push(`⛔ Unit+Feature cannot run: ${v.summary}`)
    } else {
      lines.push(`⚪ ${v.summary}`)
    }
  }

  inject(result, block(lines))
}

// OpenCode v1 entrypoint: server(ctx) -> hooks.
export const TddGuardPlugin = async (): Promise<
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

export default { id: "tdd-guard", server: TddGuardPlugin, setup }
