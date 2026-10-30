# Gotcha: [tooling/orca] — `worker-start --effort` is refused without `--model`, even for Codex's configured default model

> Tags: orca, codex, worker-start | Session: s204 (30.10.2026, Orca 1.4.x)

## What happens

`orca orchestration worker-start --agent codex --effort high …` fails validation although `~/.codex/config.toml` already
names the default model (`gpt-6-luna`). The launch never reaches Codex.

## Root cause

Orca validates the pair `--effort` / `--model` itself and does not read Codex's config to fill in the model.

## Fix

❌ Wrong: `worker-start --agent codex --effort high …`

✅ Correct: name the model every time effort is set — `--model gpt-6-luna --effort high` (or `gpt-6.1-sol` for a hard task,
per the CLAUDE.md model table).

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the rest of the Codex launch recipe
