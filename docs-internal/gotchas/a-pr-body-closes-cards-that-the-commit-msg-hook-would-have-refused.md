# gotcha: the commit-msg hook guards commit messages — a PR BODY closes cards behind its back

**Namespace:** `[tooling/git]`
**Discovered:** s111 (2026-09-01)

## What happened

PR #719 fixed **half** of #707 and said so in its own body, at length, under a heading:

```markdown
### Why this does not close #707

The #538 sibling-intersection branch ... is deliberately untouched.
```

The squash-merge closed #707 anyway. The board then showed it as `Готово`, and the card that
carried an open operator fork looked settled.

## Root cause: GitHub matches the keyword and ignores the negation around it

GitHub scans a PR body for `close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved` followed
by an issue reference. It has no notion of "not", of a question, or of a heading. `does not close
#707` contains `close #707`, so the card was closed.

This repository already knows this failure mode — it is why `.githooks/commit-msg` exists, after
s81 closed three cards while merely *describing* plans. **But that hook validates the COMMIT
MESSAGE.** A PR body never passes through it, and a squash-merge closes issues from the PR body as
well as from the commit trailer. The existing protection has a hole exactly the width of the PR
description field.

## ✅ The rule

**Never write a closing keyword next to an issue number in a PR body unless you mean to close it —
including in a sentence that denies it.** Rephrase instead:

```markdown
❌ ### Why this does not close #707
❌ This does not fix #707 completely.

✅ ### Why #707 stays open
✅ This is half of #707; the remaining fork is on the card.
```

Use `Refs #N` for a partial fix, and put `Closes #N` on its own line only when the card really is
done.

## How to notice it happened

Compare the board against the issues after merging, not just the issues:

```bash
gh issue view <N> --repo kalbac/woodev-plugin-framework --json state,stateReason
gh api repos/kalbac/woodev-plugin-framework/issues/<N>/timeline \
  --jq '.[] | select(.event=="closed") | {actor: .actor.login, created_at}'
```

A `closed` event landing one second before a `referenced` event for the merge commit is this
gotcha's signature. ⚠ Also note that `gh project item-list` **silently truncates at `--limit`** —
this board holds 338 items, so a default or `--limit 300` call reports cards as "not on board" when
they are simply past the cut.

## Related

- `../AGENTS.md` — the Backlog rule and the `git config core.hooksPath .githooks` step the hook needs
- [every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — the other case where the tooling's report is not what it appears to be
