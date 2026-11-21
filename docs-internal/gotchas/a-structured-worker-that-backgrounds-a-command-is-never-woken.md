# A structured-mode worker that starts a long command in the background and ends its turn is never woken — run gates in the foreground

**Namespace:** `[tooling/orca]` · **Discovered:** s240 (21.11.2026)

## What happens

An Orca chat (structured-mode) worker ran a gate (unit, jest, build) as a background shell job and ended its turn to "wait". Nothing wakes a worker when the shell job finishes, so the worker never resumed; the coordinator saw an escalation «stopped without reporting». The handle is `structworker_…`, so `terminal read` does not apply either.

## Root cause

A structured worker only acts inside a turn. A background job's exit is not an event that starts a new turn, so a turn that ends with a job still running is the end of the worker.

## Fix

- ❌ Brief says nothing about it; the worker backgrounds `npm run test:js` and stops.
- ✅ Every worker brief states: run gates in the FOREGROUND (raise the tool timeout instead). The watchdog for structured workers has no terminal handles — the inbox loop (`orchestration inbox` / `check --run`) is the only signal, so poll it.

## Related

- [a-structured-codex-question-is-invisible-to-the-terminal-watchdog](a-structured-codex-question-is-invisible-to-the-terminal-watchdog.md) — the same blind spot, a question instead of a finished job
- [s240 session](../sessions/s240.md)
