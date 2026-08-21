# gotcha: three agents is the real concurrency cap here — past it, gates fail in ways that look like code bugs

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happens

This machine has **15.3 GB** of RAM, with Docker Desktop (the rig) and WSL (about 2 GB) always up.
With six agents live, free memory reached **0.4 GB** and a starting Codex printed:

```text
[low_level_alloc.cc : 590] RAW: Check new_pages != nullptr failed: VirtualAlloc failed
```

Even after cutting back to **three** agents, free memory sat at 1.0 GB while their gates ran, and
three separate agents hit three different failures that all look like defects until you know why:

| Symptom | Agent | What it actually was |
|---|---|---|
| `Fatal process out of memory: Zone`, jest never printed an aggregate | critic-383 | OOM |
| `composer phpcs` failed with five PHPCS **internal exceptions**, three of them failed `shell_exec()` syntax checks on unrelated files | critic-395 | `fork()` failing under pressure |
| `composer phpcs` OOM-crashed outright | critic-412-415 | OOM; it got through with `php -d memory_limit=1G ./vendor/bin/phpcs` |

Note the shape of the middle one: PHPCS blamed **five unrelated pre-existing files**. An agent
reading that at face value files a bug against innocent code.

A Codex terminal is far heavier than a Claude one — each starts about **eleven MCP servers** of its
own.

## ✅ Correct

- **Cap the wave at three agents** while Docker and WSL are up. Release and close settled workers
  BEFORE starting the next wave, not at the end of the session.
- **Put the warning in every brief.** The wording that worked: *"several agents share this machine
  and it has been low on memory tonight … if that happens, say so plainly and retry once; never
  report a gate as green if you did not see its aggregate result, and never substitute npx jest."*
  Every s84 agent that hit an OOM after being told this reported it honestly instead of claiming a
  green gate.
- `orca orchestration worker-release` returns `retained` / `user_takeover` for any terminal the
  coordinator wrote to. Close those with `orca terminal close --terminal <handle>` or the process
  stays resident.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — why the coordinator ends up writing to every Codex terminal, which is what blocks release
- [powershell-drops-the-roots-flag-from-the-jest-command](powershell-drops-the-roots-flag-from-the-jest-command.md) — the other way a jest run reports success it did not earn
- `../wiki/orchestrating-agents-with-orca.md` — wave planning
