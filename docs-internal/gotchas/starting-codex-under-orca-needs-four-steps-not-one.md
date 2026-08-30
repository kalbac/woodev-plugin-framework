# gotcha: starting a Codex worker under Orca takes four steps — `worker-start --agent codex` alone lands in a shell

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happens

`orca orchestration worker-start --agent codex` exits 0 with `stage: input_accepted` and an effect
list that looks correct. Three times out of four in s84 the terminal it produced was **PowerShell**,
not Codex, and the injected brief was executed by the shell as a here-string:

```text
>> ## RULE 2 — Gates you MUST run before reporting
>>     npm run test:js -- --roots "<rootDir>/tests/js"
ParserError:
     | Missing closing ')' in expression.
PS D:\Projects\woodev_framework\.orca\worktrees\woodev_framework\fix-411-truncated-list>
```

`orca terminal list --json` confirms it: the title is the `pwsh.exe` path, and the worktree has
exactly one terminal. `worker-show` still reports `ready` and `dispatched`.

The same launch works for Claude every time. It also worked for Codex on the very first
new-top-level create of the session and then stopped working — including on a fresh
`new-top-level`, so it is not specific to reusing an existing worktree.

## ✅ The four-step launch that works every time

```bash
# 1. Real Codex terminal, launched with an explicit command
H=$(orca terminal create --worktree "id:<repoId>::<path>" --title codex-x --command "codex" --json \
      | jq -r .result.terminal.handle)

# 2. Clear the update dialog. `tui-idle` reports satisfied:false with
#    blockedReason "codex-update-prompt" until you do.
orca terminal wait --terminal $H --for tui-idle --timeout-ms 120000 --json
orca terminal send --terminal $H --text $'\033' --json     # ESC
orca terminal wait --terminal $H --for tui-idle --timeout-ms 60000 --json   # now satisfied:true

# 3. Deliver the task
orca orchestration dispatch --task <task_id> --to $H --inject --json

# 4. SUBMIT it — the brief arrives as "[Pasted Content N chars]" and sits there
orca terminal send --terminal $H --text "" --enter --json
orca terminal read --terminal $H --json     # look for "Working (Ns • esc to interrupt)"
```

Step 4 needed a second `--enter` more than once: if the paste has not finished rendering, the first
Enter lands on an empty composer and does nothing. Always read the buffer back.

## s86: the update dialog can appear AFTER the terminal first reads as ready

The ESC in step 2 is not a one-off at launch — **`codex` polls for updates and can raise the
dialog a second time, after a read has already shown `Ask Codex to do anything`.** In s86 a
terminal read clean, the dispatch was injected, and the step-4 `--enter` landed on the freshly
raised update dialog instead of the composer. That selects **`1. Update now`**, which runs
`npm install -g @openai/codex`; it failed with `EBUSY` (the running binary is locked on Windows),
Codex exited to a bare PowerShell prompt, and **the injected brief was lost with it**.

Nothing was damaged — the failed update left the binary untouched — but the round was wasted and
the failure reads as "Codex died", not "Codex was asked to update".

So step 4 needs a guard, not just a retry: **re-read the buffer immediately before every
`--enter`, and if the dialog is present, ESC it first.**

```bash
out=$( orca terminal read --terminal "$H" --screen --json | ... )

if echo "$out" | grep -qi "Update available"; then
    orca terminal send --terminal "$H" --text $'\e'   # never Enter — Enter means "Update now"
else
    orca terminal send --terminal "$H" --text "" --enter
fi
```

The general rule: **in this dialog Enter is not "dismiss", it is "yes"** — the default option is
`1. Update now`. Never send a bare Enter to a Codex terminal whose current frame you have not just
looked at.

## s93: `tui-idle` reports `satisfied: true` WHILE the update dialog is on screen

The step-2 recipe above says `tui-idle` "reports `satisfied:false` with `blockedReason`
`codex-update-prompt` until you do [ESC]". **In s93 it did not.** `terminal wait --for tui-idle`
returned `satisfied: true` on the first call, and a `terminal read --screen` immediately afterwards
showed the dialog still up:

```text
  ✨ Update available! 0.148.0 -> 0.149.1
› 1. Update now (runs `npm install -g @openai/codex`)
  2. Skip
  Press enter to continue
```

Had step 4 followed the `satisfied: true` without looking, the Enter would have selected
**`1. Update now`** — the s86 failure mode above, which loses the injected brief.

So `tui-idle` is **not** a usable gate for this dialog. The only reliable gate is the one s86
already arrived at for a different reason: **read the screen and grep it.** Do that after the wait
as well as before every `--enter`, and never treat `satisfied: true` as evidence the composer has
focus.

```bash
out=$( orca terminal read --terminal "$H" --screen --json )
if echo "$out" | grep -qi "Update available"; then
    orca terminal send --terminal "$H" --text $'\e'      # ESC — never Enter
fi
```

## s93: a failed `worker-start` marks the task `failed`, not `blocked`

Two `worker-start --agent codex` attempts failed at `stage: dispatch_input` with
`lastError: agent_prompt_blocked` (the dialog again). That circuit-broke the task to **`failed`**,
and the subsequent manual `dispatch --inject` was refused:

```text
Task task_… is failed; only ready tasks can be dispatched
```

Same recovery as the `blocked` case below — `task-update --id <task_id> --status ready` — but the
status to look for is different, so a recovery that only checks for `blocked` will miss it.

**Also measured in s93:** once the Codex terminal is created by hand, the same handle happily takes
a SECOND `dispatch --inject` for a follow-up task (here, a re-critic on the fix for the first
critic's finding). No new terminal, no re-launch, no ESC needed the second time — just create the
task, dispatch it, and read the buffer back to confirm it started working.

## s107: three of four launches failed, in three different ways — and the RETRY has its own trap

Four `worker-start --agent codex` launches on CLI 0.150.1, 30.08.2026. **One worked. Three failed,
and no two failed alike:**

| failure | `stage` / `lastError` | what the terminal actually held |
|---|---|---|
| A | `dispatch_input` / `agent_prompt_blocked` | a bare **PowerShell** shell — Codex never launched, and the injected brief was being eaten by the shell as a here-string (`>>` continuation) |
| B | `agent_readiness` / `Agent startup blocked: codex-update-prompt` | Codex up, update dialog on top |
| C | `dispatch_input` / `agent_prompt_blocked` | Codex up, update dialog on top |

So the `lastError` does **not** tell you which one you have. Read the buffer before choosing a
recovery: a `>>` continuation prompt means case A and needs a fresh
`terminal create --command codex`; a dialog means ESC and a re-dispatch.

### The retry trap — it silently targets the coordinator's worktree

`worker-start --retry-of <ctx> --terminal <handle>` looks complete and is not:

```text
terminal_worktree_mismatch: Terminal term_… does not belong to worktree
  cb27dca8-…::D:/Projects/woodev_framework
```

That is the COORDINATOR's worktree — the flag defaults there. The retry needs the worktree named
explicitly, with the full id from the original `worker-start` receipt's `effects`:

```bash
orca orchestration worker-start --task <task_id> --retry-of <ctx_id>   --worktree "id:<repo-uuid>::D:/…/.orca/worktrees/<repo>/<name>"   --terminal <handle> --json
```

### After three failures the task is beyond `task-update`

s93 above records `task-update --status ready` as the recovery. In s107 a task that had burned its
three attempts answered `task_not_startable` **even after** being set back to `ready` — the dispatch
context itself had circuit-broken. The way through is a **new task carrying the same spec**; the old
one gets `--status failed` with a reason, for hygiene.

### `tui-idle` is not "ready for a dispatch"

`terminal wait --for tui-idle` returns while Codex is still starting its ~11 MCP servers, and a
brief injected in that window is lost (the composer comes up empty). Poll the buffer for the line
that ends MCP startup — here `⚠ MCP startup incomplete (failed: …)` next to the
`gpt-5.6-terra` status line — and dispatch only after it appears.

### One more, cheap to miss

Codex wrote its PR body with **literal `
` escapes**, so the `Closes #673` in it never fired and
the card stayed open after the merge. Check the card actually closed; do not assume the keyword ran.

## s102: on CLI 0.150.1 the dialog did not appear, and `--inject` submitted on its own

A launch smoke-tested on **codex-cli 0.150.1** (28.08.2026, right after the subscription was
renewed) differed from the recipe above in two ways. **One observation each — the four-step recipe
STANDS; these are not licence to skip a step.**

1. **No update dialog at all.** `terminal wait --for tui-idle` returned `satisfied: true` and the
   screen showed `› Ask Codex to do anything` — the composer, with no `Update available` frame. The
   binary was current, which is the only condition under which that is expected. The screen-grep
   guard from s86/s93 cost nothing and was still run before every send; keep running it, because
   `satisfied: true` is still not evidence (s93) and the dialog can still be raised by a later
   update poll.
2. **Step 4 was not needed.** `orca orchestration dispatch --task … --to $H --inject` delivered AND
   submitted the brief by itself: the very next read showed `Working (6s • esc to interrupt)`, with
   no `[Pasted Content N chars]` sitting in the composer. The follow-up `terminal send --text ""
   --enter` was never sent.

Read the buffer back either way. If it shows `Working …` you are done; if it shows
`[Pasted Content …]`, step 4 still applies. What has NOT changed is the rule that makes step 4
dangerous: **never send a bare Enter to a frame you have not just looked at.**

### What the same smoke test proved about the shell

The historical reason Codex must run in an Orca terminal at all (`codex-shell-sandbox-broken-windows`)
is that a dead shell makes it FABRICATE file contents rather than report failure. Retested with a
canary — three facts it could only get by really reading and really executing:

| Asked | Answered | Truth |
|---|---|---|
| third function in `bin/php-version-matrix.php` | `woodev_composer_platform_php` | ✅ |
| that function's `@since` | `2.0.2` | ✅ |
| `git log --oneline -1` | `39a910 docs(s102): …` | ⚠ really **b39a910** |
| a real `phpunit --filter` run | `OK (7 tests, 13 assertions)` | ✅ |

The shell is genuinely live — it ran `Get-Content`, `git log` and PHPUnit for real. But **it dropped
the leading character of the commit hash.** Not fabrication (the subject matched verbatim, the other
six characters were right), yet exactly the shape a reader accepts as true. **Keep giving a Codex
round at least one fact you already know**, and prefer facts where a one-character slip is visible.

Two environment notes from the same run, neither blocking: every tool call printed
`PostToolUse hook (failed) — error: hook exited with code 1` (the `security-guidance@claude-plugins-official`
hooks registered in `~/.codex/hooks.json`), and `orca terminal close` returned `ok: true` while the
tab stayed open as a bare shell.

## Recovering a botched launch

`worker-stop` moves the task to `blocked`, and `dispatch` then refuses it:

```text
Task task_… is blocked; only ready tasks can be dispatched
```

So the recovery is:

```bash
orca orchestration worker-stop --dispatch <ctx_id> --json
orca orchestration task-update --id <task_id> --status ready --json
# … then the four steps above
```

## Also worth knowing

`orca orchestration worker-release` returns `state: "retained"`, `reason: "user_takeover"` for any
terminal the coordinator ever wrote to with `terminal send` — which, given step 4, is every Codex
worker. Those must be closed with `orca terminal close --terminal <handle>`.

## s108 (30.08.2026) — the "four steps" were the UPDATE DIALOG, and the operator removed its cause

The operator refused the s107 framing on the record — «Codex мы использовали с самого начала, и он
отлично работал из-под CLI Orca… Поэтому я не верю, что Codex вот так вот просто на ровном месте
перестал работать» — and a clean experiment says he was right about the part that matters.

`orca orchestration worker-start --task <id> --worktree id:<…> --agent codex` was run once, with no
ritual around it. Measured:

- It created a **real Codex terminal**, not a PowerShell one — the TUI header read
  `OpenAI Codex (v0.150.1)`, `directory: …\codex-native-probe-683`, `permissions: YOLO mode`. The
  s84 "three times out of four it is a bare shell" did NOT reproduce.
- The model defaulted to `gpt-5.6-terra` on its own. No `--model` was passed, and none should be.
- It exited `state: failed`, `lastError: agent_prompt_blocked` — and the buffer named the cause
  exactly: the **Codex update dialog** (`0.150.1 -> 0.151.0`, "Press enter to continue").
- Sending `3` (Skip until next version) cleared it, `terminal wait --for tui-idle` then satisfied,
  and one `terminal send --text "" --enter` submitted the queued prompt. Codex went to `Working`.

**The operator then updated Codex, which removes the cause rather than working around it.** Step 2
of the recipe above exists only to survive that dialog; on an up-to-date Codex there is nothing for
it to dismiss. Keep the step — the dialog returns with every new release, and s86 showed it can
appear after a clean read — but stop describing a four-step ritual as what Orca requires. Orca
requires one command. Codex's own updater is what adds the other three.

## ⚠ s108, NEW and unexplained: the dispatch body does not always reach the prompt

After the dialog was cleared and the prompt submitted, the Codex worker reported:

> No substantive assignment reached my prompt — only the dispatch wrapper with task id
> task_59874caef6be

The Orca lifecycle preamble arrived; **the task spec did not.** The worker had nothing to do and
said so. Re-delivering the same brief with `orca terminal send --text "<brief>" --enter` worked
immediately, and the same Codex then produced a full review with `file:line` citations.

This is very likely what actually happened in s107 to the worker that "traded the task for a
receipt": it never had a task. Its own explanation — that it could not reach the `orca` CLI — was
recorded as a measured fact and was not verified; s108 measured the CLI as reachable. Card #683
carries this.

**Operationally:** after submitting a Codex worker's prompt, read the buffer back and confirm the
TASK text is in it, not just the preamble. A `Working` spinner proves it is doing something, not
that it received what you sent.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the s83 half of this: the receipt lies about delivery
- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why Codex must run in an Orca terminal at all
- `../wiki/orchestrating-agents-with-orca.md` — the launch recipe
