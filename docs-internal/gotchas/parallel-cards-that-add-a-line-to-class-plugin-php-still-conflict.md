# Gotcha: [tooling/parallel-agents] — two parallel cards that each «add one line» to `includes/class-plugin.php` still conflict

> Tags: parallel-agents, merge, plugin-wiring | Session: s204 (30.10.2026, Ozon W5 + W6)

## What happens

Ozon W5 (phone-first checkout) and W6 (export) ran in parallel. Both briefs said «touch `class-plugin.php` only to add the
wiring line». W5 nevertheless rewrote a method body in that file, so the second PR to merge conflicted. The coordinator
resolved it by hand (`2e4baa7`) and re-ran every gate (249 tests).

## Root cause

The plugin class is the single wiring point of every feature. A «minimal line» rule in a brief is an intention, not a
constraint — a worker that needs the line to sit in a restructured method restructures it.

## Fix

❌ Wrong: rely on the brief's «one line only» for two parallel cards that both end in the plugin class.

✅ Correct: treat the plugin class as a shared file — merge such cards **serially**, let the coordinator rebase the second
one (or resolve the conflict), and re-run the second card's gates after the merge. Where the wiring is declarative (a list
of providers), a card should add an entry to the list rather than edit a method.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — a worktree's green gate is not the merged tree's gate
