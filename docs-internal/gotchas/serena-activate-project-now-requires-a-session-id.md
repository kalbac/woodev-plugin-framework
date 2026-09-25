# [tooling/serena] `activate_project` now requires a session id, and the first activation after an upgrade rewrites `.serena/project.yml`

> Namespace: `tooling/*` — added session 138 (2026-09-25), measured on the macOS laptop

## The trap

Serena's tool signature changed. A call that worked in every earlier session now fails before it
reaches the project:

```text
Error executing tool activate_project: 1 validation error for applyArguments
session_id
  Field required [type=missing, ...]
```

Falling back to `get_current_config` to see what is active does not help either — it answers
`No active project. Ask the user to provide the project path ...` and lists the known projects,
which reads like a broken installation rather than a missing argument.

The session id is handed out by **`initial_instructions`**, the tool whose own description says to
call it first. So the rule that was already in force for a different reason — read the Serena manual
before working — is now load-bearing: without that call there is no id, and without the id nothing
symbolic can be activated.

## Root cause

`activate_project` gained a required `session_id` parameter; `initial_instructions` ends with a
`<session>` block carrying the value for this session. It is per-session, not per-project, so it is
obtained once and reused for every tool that asks for it.

Separately: the first activation after a Serena upgrade **regenerates `.serena/project.yml`** from
the new version's template — the language-server list grows (`deno`, `gleam`, `qml`, …) and new keys
appear (`included_apis`, `excluded_apis`, `agent_interface`). This shows up as an unexplained
modified file in `git status` at session start, which is easy to mistake for someone's leftover edit.

## Fix

❌ Wrong — the call every earlier session made:

```text
activate_project(project="/Users/…/woodev-plugin-framework")
```

✅ Correct — two calls, in this order:

```text
initial_instructions()                    # → "Your Serena session id is `8cf49294`"
activate_project(project="/Users/…/woodev-plugin-framework", session_id="8cf49294")
# → "Active language servers: php, typescript."
```

⚠ **Put this in every subagent brief that touches PHP**, with the worker's OWN worktree path. The
old brief wording ("activate by path, verify a `find_symbol` result reports a path under your
worktree") is still right and still necessary — it is now simply insufficient on its own.

For the regenerated config: it is a real upgrade artefact, so either commit it deliberately or
`git checkout -- .serena/project.yml`. Do not leave it modified in a worktree that will be removed —
`orca worktree rm` refuses a worktree with any modified tracked file (gotcha
[reusing-a-worker-terminal-needs-its-worktree-too](reusing-a-worker-terminal-needs-its-worktree-too.md)).

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the path half of the same rule, and what it cost in s83
- [reusing-a-worker-terminal-needs-its-worktree-too](reusing-a-worker-terminal-needs-its-worktree-too.md) — the `worktree rm` refusal this file's artefact triggers
- [serena-index-vs-git-worktree](serena-index-vs-git-worktree.md) — the other way Serena and worktrees disagree
