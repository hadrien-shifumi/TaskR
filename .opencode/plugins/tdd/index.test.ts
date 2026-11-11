import { afterEach, describe, expect, test } from "bun:test"
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs"
import { homedir, tmpdir } from "node:os"
import path from "node:path"
import {
  findRoot,
  guardBashAfter,
  guardBashBefore,
  guardEditWrite,
  isDevTestCommand,
  isE2eCommand,
  isNarrowRun,
  loadState,
  promptSession,
  recordBashRun,
  resultText,
  runnerArgs,
  runnerPath,
  saveState,
  summarize,
  turnGate,
  type TddState,
} from "./index"

function state(overrides: Partial<TddState> = {}): TddState {
  return { phase: "green", phase_at: Date.now(), dirty: false, ...overrides }
}

const sessions: string[] = []

function useSession(): string {
  const sessionId = `ses_tdd_test_${Math.random().toString(36).slice(2)}`
  sessions.push(sessionId)
  return sessionId
}

afterEach(() => {
  for (const sessionId of sessions.splice(0)) {
    const file = path.join(homedir(), ".cache", "opencode-tdd", `${sessionId}.json`)
    if (existsSync(file)) rmSync(file)
  }
})

describe("verdict summarization", () => {
  test("a clean run is green", () => {
    expect(summarize(0, "OK (12 tests, 30 assertions)").state).toBe("green")
  })

  test("a failing run is red", () => {
    const verdict = summarize(1, "Tests: 2 failed, 10 passed")
    expect(verdict.state).toBe("red")
    expect(verdict.summary).toContain("Tests:")
  })

  test("parses the pest compact JSON as green", () => {
    const out = '{"tool":"pest","result":"passed","tests":1435,"passed":1435,"assertions":8301,"duration_ms":20732}'
    const verdict = summarize(0, out)
    expect(verdict.state).toBe("green")
    expect(verdict.summary).toContain("1435 tests")
    expect(verdict.summary).toContain("8301 assertions")
  })

  test("parses the pest compact JSON as red with failure names", () => {
    const out =
      '{"tool":"pest","result":"failed","tests":10,"passed":9,"failed":1,' +
      '"failures":[{"test":"P\\\\Tests\\\\Browser\\\\IssueDetailTest::__pest_evaluable_x"}]}'
    const verdict = summarize(1, out)
    expect(verdict.state).toBe("red")
    expect(verdict.summary).toContain("10 tests")
    expect(verdict.failures[0]).toContain("IssueDetailTest")
  })

  test("extracts FAILED lines from pest formatted output", () => {
    const out = "FAILED  Tests\\Feature\\FooTest::test_bar\nTests: 1 failed, 9 passed, 10 total"
    const verdict = summarize(1, out)
    expect(verdict.state).toBe("red")
    expect(verdict.failures.some((f) => f.includes("FooTest"))).toBe(true)
  })

  test("runs the real runner on a test file edit and reports green", async () => {
    const sessionId = useSession()
    // guardEditWrite receives the whole hook event and injects into
    // event.result.content (V2 shape: existing text parts).
    const event = { result: { content: [{ type: "text", text: "" }] } }
    await guardEditWrite(
      "edit",
      { path: path.resolve("tests/Feature/McpKnowledgeToolsTest.php") },
      event,
      sessionId,
    )
    expect(resultText(event.result)).toContain("🟢 GREEN")
    expect(loadState(sessionId).phase).toBe("green")
  }, 60_000)
})

describe("runner selection", () => {
  test("findRoot accepts a Pest-only project", () => {
    const root = mkdtempSync(path.join(tmpdir(), "tdd-pest-"))
    mkdirSync(path.join(root, "vendor", "bin"), { recursive: true })
    writeFileSync(path.join(root, "vendor", "bin", "pest"), "")
    try {
      expect(findRoot(path.join(root, "app", "Foo.php"))).toBe(root)
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  })

  test("prefers pest when both binaries exist", () => {
    const root = mkdtempSync(path.join(tmpdir(), "tdd-both-"))
    mkdirSync(path.join(root, "vendor", "bin"), { recursive: true })
    writeFileSync(path.join(root, "vendor", "bin", "pest"), "")
    writeFileSync(path.join(root, "vendor", "bin", "phpunit"), "")
    try {
      expect(runnerPath(root)).toBe(path.join(root, "vendor", "bin", "pest"))
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  })

  test("falls back to phpunit when pest is absent", () => {
    const root = mkdtempSync(path.join(tmpdir(), "tdd-phpunit-"))
    mkdirSync(path.join(root, "vendor", "bin"), { recursive: true })
    writeFileSync(path.join(root, "vendor", "bin", "phpunit"), "")
    try {
      expect(runnerPath(root)).toBe(path.join(root, "vendor", "bin", "phpunit"))
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  })

  test("adds the memory limit to the runner args", () => {
    const args = runnerArgs(["--testsuite", "Unit,Feature"])
    expect(args[0]).toBe("-d")
    expect(args[1]).toMatch(/^memory_limit=/)
    expect(args.slice(2)).toEqual(["--testsuite", "Unit,Feature"])
  })
})

describe("command classification", () => {
  test("detects E2E commands", () => {
    expect(isE2eCommand("composer test:e2e")).toBe(true)
    expect(isE2eCommand("vendor/bin/phpunit --testsuite=Browser")).toBe(true)
    expect(isE2eCommand("composer test")).toBe(false)
  })

  test("detects dev test commands", () => {
    expect(isDevTestCommand("composer test")).toBe(true)
    expect(isDevTestCommand("vendor/bin/phpunit")).toBe(true)
    expect(isDevTestCommand("php artisan migrate")).toBe(false)
  })

  test("detects narrow runs", () => {
    expect(isNarrowRun("vendor/bin/phpunit --filter=testFoo")).toBe(true)
    expect(isNarrowRun("vendor/bin/phpunit tests/Feature/FooTest.php")).toBe(true)
    expect(isNarrowRun("composer test")).toBe(false)
  })
})

describe("bash run recording", () => {
  test("a full green run clears the dirty flag", () => {
    const current = state({ dirty: true })
    recordBashRun(current, "composer test", "OK (12 tests)", false)
    expect(current.phase).toBe("green")
    expect(current.dirty).toBe(false)
  })

  test("a narrow red run marks the suite red", () => {
    const current = state()
    recordBashRun(current, "vendor/bin/phpunit --filter=testFoo", "Tests: 1 failed", true)
    expect(current.phase).toBe("red")
  })

  test("an E2E run records its timestamp", () => {
    const current = state()
    recordBashRun(current, "composer test:e2e", "", false)
    expect(current.e2e_at).toBeGreaterThan(0)
  })
})

describe("E2E pre-tool gate", () => {
  test("refuses E2E while the suites are red", () => {
    const sessionId = useSession()
    saveState(sessionId, state({ phase: "red" }))
    expect(() => guardBashBefore("bash", { command: "composer test:e2e" }, sessionId)).toThrow(/final gate/)
  })

  test("allows E2E when the suites are green and clean", () => {
    const sessionId = useSession()
    saveState(sessionId, state())
    expect(() => guardBashBefore("bash", { command: "composer test:e2e" }, sessionId)).not.toThrow()
  })
})

describe("V2 tool results", () => {
  test("extracts text from content parts", () => {
    expect(resultText({ content: [{ type: "text", text: "Tests: 1 failed" }] })).toContain("Tests: 1 failed")
  })

  test("extracts text from a plain output field", () => {
    expect(resultText({ output: "OK (3 tests)" })).toBe("OK (3 tests)")
  })

  test("a failed V2 run is recorded as a regression", () => {
    const sessionId = useSession()
    saveState(sessionId, state())
    guardBashAfter(
      "bash",
      { command: "composer test" },
      { status: "error", result: { content: [{ type: "text", text: "Tests: 3 failed" }] } },
      sessionId,
    )
    expect(loadState(sessionId).phase).toBe("regression")
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

describe("turn gate", () => {
  test("prompts a red session through the V2 API, without ctx.client", async () => {
    const sessionId = useSession()
    saveState(sessionId, state({ phase: "red" }))
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, sessionId)

    expect(calls).toHaveLength(1)
    expect((calls[0] as { text: string }).text).toContain("RED")
  })

  test("stays silent for a green, clean session", async () => {
    const sessionId = useSession()
    saveState(sessionId, state())
    const calls: unknown[] = []
    const ctx = { session: { prompt: async (input: unknown) => calls.push(input) } }

    await turnGate(ctx, sessionId)

    expect(calls).toEqual([])
  })
})
