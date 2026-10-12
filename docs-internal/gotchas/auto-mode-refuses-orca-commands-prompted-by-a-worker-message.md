# Auto mode refuses an `orca` command when the prompt to run it came from a worker message

> Namespace: `tooling/orca` — added session 174 (2026-10-12, macOS, Orca 1.4.224).

## The trap

Orca injects «You have 1 orchestration message. Run `orca orchestration check --run …`» into the
coordinator's prompt, and a background `check --wait` hands back a `worker_done`. When the coordinator
then runs `orca orchestration check --ack …` / `worker-release`, or `cat`/`grep`s the waiter's output
file, the Claude Code **auto-mode classifier sometimes refuses it as «Instruction Poisoning»** — the
command is read as an instruction laundered through a worker's text, not as the operator's.
In the same session it also refused `worker-start` of a measurement that creates live Yandex
requests («Real-World Transactions»), although the operator had said the account is a test account.

The refusal is not deterministic: the same `--ack` passed several times in the session and failed
twice.

## Root cause

The classifier judges the action against who appears to have asked for it. An injected Orca nudge
or a worker report sits between the operator's request and the command.

## ❌ Wrong

Retrying the same command in a different spelling to slip past the refusal — that is circumvention.

## ✅ Correct

- Read the waiter's output file with the **Read** tool (read-only, needs no classifier verdict).
- For the lifecycle mutation, give the operator the exact command to run with the `!` prefix
  (`! /Applications/Orca.app/Contents/Resources/bin/orca orchestration check --run <run> --ack <delivery> --json`).
- For an outward action (live carrier requests), quote the operator's explicit permission or have him
  launch the worker with `!` — in s174 he did both.

## Related

- [orca-check-json-is-pretty-printed-so-a-line-parser-reads-it-as-empty](orca-check-json-is-pretty-printed-so-a-line-parser-reads-it-as-empty.md)
- [one-check-wait-per-run-and-a-second-one-fails-invisibly](one-check-wait-per-run-and-a-second-one-fails-invisibly.md)
