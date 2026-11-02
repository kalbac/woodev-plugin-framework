# AGENTS.md — Woodev Plugin Framework
> For ALL AI agents (Claude, Gemini, Cursor, GPT, etc.). Keep updated.
> **Claude Code agents:** read `CLAUDE.md` too — it adds the Claude-specific MCP tooling (Serena, Context7). It does not replace this file, and it restates nothing from it.
> **Architecture reference** (subsystems, base classes, seams): `docs-internal/wiki/architecture.md` — not loaded at session start, open it when the task needs it.

---

## ⚡ Session Start (mandatory — this is the CANONICAL list; CLAUDE.md and AGENT-RULES.md point here)

0. **Sync first — the project runs from TWO machines** (desktop + laptop, one at a time).
   `git fetch --all --prune`, compare the local branches with `origin`, pull. If the pull moved
   `package-lock.json` or `composer.lock` → `npm ci` / `composer install`.
1. **Read `docs-internal/next-session-prompt.md`** — the per-session handoff: what the last session
   left for you, its **carry-over commitments**, and known traps (~1 min). Format and gate:
   `DOCS-SCHEMA.md` → Handoff Format
2. **Read `docs-internal/CURRENT-STATE.md`** — phase status, known bugs, next actions (~1 min)
3. **Read `docs-internal/GOTCHAS.md`** — the topic map (~30 s). Open the `gotcha-index/{topic}.md` files your task touches — `[tooling/*]`, `[testing/*]` and `[rig/*]` almost always — then `gotchas/{slug}.md` for the ones that apply.
4. **Area-specific docs as needed** — relevant `docs-internal/adr/` and `docs-internal/wiki/` files (navigation hub: `docs-internal/DOCS-INDEX.md`)

---

## ✅ Session End (mandatory — CANONICAL list)

1. Update `docs-internal/CURRENT-STATE.md` — phase table, bugs, next actions
2. Write `docs-internal/sessions/sNN.md` — the session's own file: what was done, key decisions, test counts, commit hash. Then add ONE line for it to `docs-internal/SESSION-LOG.md`, which is only an index
3. **⚙️ Compilation step** — for each new gotcha discovered this session:
   - Create `docs-internal/gotchas/{slug}.md` (root cause + ❌ wrong / ✅ correct code + Related links)
   - Add ONE index line to the right `docs-internal/gotcha-index/{topic}.md` (a new topic also gets a row in the `GOTCHAS.md` map)
   - Update `docs-internal/wiki/*.md` if a pattern was clarified
   - Read `docs-internal/DOCS-SCHEMA.md` for exact format rules
4. **Plugin-author lessons → skills** — every trap found this session that the author of the next plugin would hit is in `.ai/skills/woodev-plugin-core/` or `woodev-shipping-plugin/` (a gotcha file alone is NOT enough: the skills are what that author reads). Ask of each new gotcha and merged PR: would a new plugin hit this again?
5. **Audit the board** — move the session's cards (`В работе` → `Готово`), close cards resolved by unrelated work, file cards for anything that surfaced but was never written down (see Backlog rule below)
6. **Update `docs-internal/next-session-prompt.md`** — replace it with the handoff for the next session (write it for someone with zero context)
7. **Close the agent terminals this session started** — `orca terminal list --json`, then `orca terminal close` for each of yours (a critic that already sent `worker_done` is still a LIVE terminal holding RAM and a rate-limit slot; check at session START too).
8. **Disk sweep** — delete every artifact a session left on the operator's machine (`~/Desktop`,
   `~/Downloads`, `~/Documents`: release zips, screenshots, probes, exports). Reproducible or unneeded →
   delete without asking; anything kept is named in the handoff with the step after which it goes.
   Global rule: `~/.agents/AGENTS.md` → «Сохрани сессию» step 4.
9. Commit with Conventional Commits format (`feat:`, `fix:`, `docs:`, etc.)
10. **Push every branch that carries work** — git is how the other machine gets it; a local-only
    branch simply does not travel.

---

## 📍 Current Phase
> **Always read `docs-internal/CURRENT-STATE.md`** — single source of truth for phase status, bugs, next actions.
> Do NOT duplicate sprint details here. This section is a pointer only.

---

## Project in one paragraph

**Woodev Plugin Framework** — PHP library (PHP 7.4–8.x) providing a scaffold for developing WooCommerce plugins. Ships as a vendored dependency bundled inside each plugin. Multiple plugins run simultaneously; bootstrap selects highest framework version. 10+ dependent plugins in production. Codebase: `woodev/` directory, no global namespace for legacy classes, `Woodev\Framework\*` PSR-4 for new code.

---

## 📚 Documentation Structure — two tiers

This project has **two documentation directories** with different audiences, publishing rules, and editing conventions.

| Directory | Audience | Published | Built with | Linted | Purpose |
|-----------|----------|-----------|------------|--------|---------|
| `website/` | Plugin developers (public) | GitHub Pages (`docs.yml`, on push to `main`) | Astro Starlight | ✅ markdownlint | Usage guides, API reference, tutorials |
| `docs-internal/` | AI agents + maintainers | ❌ Not published | ❌ excluded | ⚠️ partial | Session logs, gotchas, ADRs, operational state |

### Working with `website/` — public documentation

The public site is an Astro Starlight project in `website/` (English only; pages in `website/src/content/docs/`). Edit, preview and build rules: `docs-internal/DOCS-SCHEMA.md` → "Public docs".

### Working with `docs-internal/` — internal technical docs

**How to edit:**
1. Edit files directly — no build step
2. Follow format rules in `docs-internal/DOCS-SCHEMA.md`
3. Session logs and gotchas excluded from markdownlint (in `.markdownlintignore`)
4. All files tracked in git — never gitignore docs-internal/ (the only ignored path is the gate's
   generated `next-session-prompt.md.prev` snapshot). The repo is PUBLIC, so Actions consume no
   quota — history and the two-second-failure symptom: card #583 + its gotcha.

**What goes here:**
- `CURRENT-STATE.md` — phase status, known bugs, next actions
- `SESSION-LOG.md` — index of sessions, one line each (newest at top)
- `sessions/sNN.md` — the per-session detail
- `GOTCHAS.md` — topic map → `gotcha-index/{topic}.md` → `gotchas/{slug}.md` atomic detail files
- `AGENT-RULES.md` — workflow + architecture rules for AI agents
- `DOCS-INDEX.md` — navigation hub for all internal docs
- `DOCS-SCHEMA.md` — doc format and lint rules
- `FUTURE-BACKLOG.md` — deferred features and technical debt
- `adr/` — Architecture Decision Records
- `wiki/` — compiled topic references
- `archive/` — resolved historical documents

**What does NOT go here:**
- ❌ Public-facing API documentation (→ `website/`)
- ❌ User guides, tutorials (→ `website/`)
- ❌ Public site configuration (→ `website/astro.config.mjs`)

---

## Repository map

The directory overview and tech-stack reference are maintained in [the documentation index](docs-internal/DOCS-INDEX.md#repository-map) so this gateway stays within its 28 KB limit. Session-start and session-end rules remain here.

## 🛠 Dev environment

**Two machines** — a Windows desktop and a macOS laptop, one at a time (s135). What travels by git, what by the gitignored `.machine-transfer/` bundle, and what differs per OS: `docs-internal/wiki/two-machine-setup.md`.

```bash
# Install dependencies
composer install

# Run all checks
composer check              # phpcs + phpstan + unit tests

# Individual checks
composer phpcs              # code style check (warning level IS on since #139)
composer phpcbf             # auto-fix code style
composer phpstan            # static analysis
composer test               # unit tests (Brain Monkey, no WP)
composer test:integration   # integration tests (requires wp-env)

# Run single test file
./vendor/bin/phpunit tests/unit/BootstrapTest.php

# JS tests + build
npm run test:js                                   # jest — CI gate (test-js job); jest-unit.config.js scopes roots, agent worktrees excluded
npm run test:e2e                                  # Playwright vs the LIVE RIG (:8973), not CI — #723
npm run build                                     # build the 8 React bundles (CI: assets-parity job)
npm run lint:ts-baseline                          # TypeScript-by-default gate for src/ — CI (test-js)
npm run lint:i18n-sources                         # every source msgid must be in the catalogue — CI (lint job, #791).
                                                  # Needs wp-cli 2.12.0; with the rig up it copies one out of the
                                                  # container and caches it under ~/.cache, so a worktree works too (#800)
npm run typecheck                                 # tsc --noEmit over src/ — CI gate (test-js job)

# Docs
cd website && npm ci && npm run dev   # preview public docs locally
npx markdownlint-cli2 "website/src/content/docs/**/*.{md,mdx}"  # lint public docs
```

- **Line length is the one thing `composer phpcs` does NOT check** (#139). 120 is a soft target
  (1393 lines already over), so measure it on demand:
  `vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev`. Every other
  warning-level sniff DOES fail the run now — see that file's description for why it needs its own.
- **Enable the hooks once per clone: `git config core.hooksPath .githooks`.** `commit-msg`
  refuses a GitHub closing keyword (`closes`/`fixes`/`resolves #N`) anywhere except alone on its
  own line — GitHub executes those literally even inside a quote, and s81 closed three cards that
  way while merely describing plans; a deliberate `Closes #123` on its own line still works.
  `post-merge`/`post-checkout`/`post-commit`/`post-rewrite` run `composer dump-autoload` when a
  git operation moved framework sources (#802) — `vendor/` is gitignored, so git never refreshes
  that snapshot itself. **`pre-push` rebuilds the bundles and runs `lint:i18n`/`lint:i18n-sources`/`lint:mo`/`lint:js-i18n`
  by EXIT CODE when a push touches `src/` or `woodev/`** (~22 s; skipped otherwise) — those two gate
  families were 7 of the 8 first-try CI reds since 05.09, because a worker may not build bundles and
  both gates read msgids out of the BUILT one (s134). All hooks are committed `100755`: POSIX git silently IGNORES a
  non-executable hook, and on Windows `core.fileMode=false` hides that from you.
- **Point github.com at `gh` once per machine: `gh auth setup-git`** (#560, operator 27.08.2026).
  Without it `git push` hangs forever with no output, no error and no prompt. Verify BEFORE the
  first push: `git config --global --get-regexp credential`. Do not hand-roll the helper — why,
  and the exact symptom: gotcha `git-credential-manager-hangs-silently-in-an-agent-session`.
- Never run `npx jest` directly — it loses the wp-scripts jsdom environment (gotcha `npx-jest-bypasses-wp-scripts-jsdom`). `jest-unit.config.js` scopes `roots` to `tests/js`, so a bare `npm run test:js` no longer counts agent worktrees (gotcha `jest-scans-agent-worktrees-inside-the-repo`, fixed s107/#188)
- Integration tests require `WP_TESTS_DIR` env var or `npx @wordpress/env start` (the bare `wp-env` is a stub package)
- **Merge gate:** every CI job green individually (incl. `test-js` and `assets`), each with state CLEAN — not just "`composer check` passes". `main` has no required-check gate, so verify each job yourself before merging.

---

## 🎯 Coding Principles (Karpathy-inspired)

4 principles that prevent common LLM coding failures. Apply to every task.

1. **Think Before Coding** — state assumptions, surface alternatives, ask when unclear
2. **Simplicity First** — minimum code; no speculative features
3. **Surgical Changes** — touch only what the task requires; preserve installed-site data contracts (internal APIs may break on the v2 branch — see clean-break policy)
4. **Goal-Driven Execution** — define success as a verifiable check (`composer check` passes)

**Full rules with Do/Don't tables:** `docs-internal/AGENT-RULES.md` → "Workflow Rules"

---

## ✅ Definition of Done

A task is DONE only when:
1. Code is written (type declarations, docblocks, backward compat preserved)
2. `composer check` passes without errors (phpcs + phpstan + unit tests)
3. Jest is green: `npm run test:js` (never `npx jest`)
4. New/modified behavior is covered by tests
5. `docs-internal/CURRENT-STATE.md` is updated
6. `docs-internal/sessions/sNN.md` is written and indexed in `SESSION-LOG.md`
7. The board card is moved to `Готово` (see Backlog rule)
8. `git commit` is made with Conventional Commits format

---

## ⚠️ Critical gotchas — quick reference

Full details + code examples in `docs-internal/gotchas/`. Open your topic from the `docs-internal/GOTCHAS.md` map.

**Naming:** `woodev` (single d), `Woodev` prefix — `wooddev` is always wrong.

**Backward compatibility — clean-break policy (v2 line, D-2 2026-06-03; the `refactor/platform-v2-clean-break` branch merged to `main` 2026-06-04):**
- **Internal code is FREE TO BREAK** on the v2 line (`main`) — class/method names, registration shape, namespacing. Do NOT add `@deprecated`/`class_alias`/`_deprecated_function` shims for moved internal APIs; delete existing ones.
- **Installed-site data contracts are RELEASE-BLOCKING** — option keys, license/instance IDs, gateway/shipping method IDs, hook names, cron, REST namespaces, AJAX actions, admin slugs, meta keys. Preserve byte-for-byte (enforced per-plugin at rewrite time).
- Full policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md`; operating rules: `docs-internal/platform-v2-execution-protocol.md`.
- Legacy namespace: `Woodev_*` classes; new code: `Woodev\Framework\*` PSR-4 (include-based, not Composer autoload at runtime).

**Serena MCP (PHP navigation) — binds agents that HAVE Serena:** never raw `Read` on `.php` files (Serena is pre-indexed and more
accurate). Mandatory (operator, s60): verify it is connected at session start; if it is missing, report to the operator before any PHP
work, do not silently fall back to `Read`; every subagent brief touching PHP repeats this rule. Serena is a Claude Code MCP: an agent
without it (Codex) says so once and proceeds with git/shell inspection — stalling on it is wrong (s83). Full text:
`docs-internal/AGENT-RULES.md` → "Use Serena MCP".

**Type safety:**
- Type declarations required on ALL parameters and return types
- Docblocks required on ALL public/protected methods (`@since`, `@param`, `@return`)
- Pure methods (output depends only on inputs) must be `static`

**WooCommerce integration:**
- Use `Woodev_Order_Compatibility` for HPOS-safe order data access
- `Woodev_Plugin_Compatibility` for WP/WC version checks
- Gateway plugins extend `Woodev_Payment_Gateway_Plugin`, not `Woodev_Plugin` directly

**Docs:**
- Public docs (`website/`) use the `%%FRAMEWORK_VERSION%%` placeholder — never hardcode a version
- Internal docs and all agent work, dispatched agents' reasoning and reports included (operator, 09.10.2026) — English; quoted operator words, domain data and msgids keep their language (`DOCS-SCHEMA.md` → Language Rule)
- Gotchas go in `docs-internal/gotchas/`, NOT in `website/`

---

## Conventions

| Area | Rule |
|------|------|
| Translatable strings | **Four rules, by WHO READS the string** (operator, 29.08.2026, #567). **Storefront → the msgid is ENGLISH** and the Russian arrives from the catalogue: a shop's frontend may run an English locale even though its admin never does. **Admin → Russian msgid is fine and stays** (do not spend effort anglicising it); an admin string that IS in English must be translated in the catalogue. **Logs, exception texts and anything else that never reaches a screen → either language, and it need not be wrapped in `__()`/`_e()` at all — a plain string is correct.** Text domain, wherever a wrapper IS used: `woodev-plugin-framework` (pinned by `TextDomainConsistencyTest`). Classify by the RENDER PATH, never by the file's directory — gotcha `classify-an-i18n-string-by-its-render-path-not-its-file-path`. |
| PHP style | WordPress Coding Standards (tabs, snake_case, PHPDoc) |
| Classes | `Snake_Case`. **New code is authored directly in namespaces** (`Woodev\Framework\*` PSR-4, e.g. `Woodev\Framework\Shipping\Shipping_Plugin`) — do NOT write new code under the legacy global `Woodev_*` shape. Legacy `Woodev_*` exists only in not-yet-migrated files. |
| Methods/variables/hooks | `snake_case` |
| Visibility | default `private`, `protected`/`public` only when needed |
| Arrays | Short syntax `[]` **only — never `array()`** in new or modified code |
| Frontend (`src/`) | **TypeScript is the default for NEW files.** Existing `.js`/`.jsx` is migrated only on touch, not in bulk — enforced by `npm run lint:ts-baseline` (baseline: `scripts/ts-baseline.txt`), gated in CI's `test-js` job alongside `npm run typecheck`. `woodev/**/assets/js/frontend/` (raw JS served to the browser as-is) is explicitly OUT of this scope — do not take TypeScript there (#542, 26.08.2026). |
| Reusable JS | PHP-driven; hand-written JS stays a minimum. See `AGENT-RULES.md` Rule 9 (#107). |
| Settings label | **Short essence, not a full sentence; three layers share the work** (operator, 31.08.2026). Full rule, its worked example and the generalisation he REJECTED: `AGENT-RULES.md` Rule 10a. |
| Settings help text | **`tooltip`/`desc_tip` and `description` COEXIST** — the second is not an alternative to the first (operator, 25.08.2026, corrected 31.08.2026). When each earns its place: `AGENT-RULES.md` Rule 10b. |
| Merchant-facing vocabulary | **No jargon in anything a merchant reads** (operator, 31.08.2026) — «чекаут» and «фреймворк» specifically. The two replacements and the scope: `AGENT-RULES.md` Rule 10c. |
| Git | Conventional Commits (`feat:`, `fix:`, `docs:`, etc.) |
| Version | Stored in `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) |
| `@since` | Planned release is `composer.json` → `extra.woodev.planned-release`, currently `2.0.23` (released `VERSION` is `2.0.22`); semver rule in `AGENT-RULES.md` Rule 5, gated by `SinceTagCeilingTest`. Raising `VERSION` on `main` publishes a release (#285). |

---

## Git workflow
- Commit after each verified, working unit of change
- Message: `type(scope): description` — e.g. `fix(bootstrap): handle missing WC dependency`
- Never commit broken code — `composer check` must pass
- Breaking changes: add `!` after type + `BREAKING CHANGE:` footer
- Branch naming: `{type}/{description}` — e.g. `feat/new-subsystem`

---

## 🔍 Code review rule

**Order: code → review → fix → commit. Never commit first, review later.**

Run review before committing when changes touch:
- `woodev/class-plugin.php` or `woodev/bootstrap.php` (core architecture)
- `woodev/payment-gateway/` (high complexity, ~3.6k lines in main file)
- Public API surface (new/changed public methods, new classes)
- Deprecation/removal of existing functionality
- Changes spanning 3+ files

**Skip for:** docs-only, config-only, tests-only, small isolated single-file fix.

**Findings are rated by the real shop** — `AGENT-RULES.md` → "Rate findings by the real shop".

---

## 🧠 Gotcha recording rule

**When:** Record a gotcha **immediately when discovered**, not at session end.

**How (mandatory steps):**
1. **Qualify** — non-obvious + can cause bug + contradicts assumptions? If no → skip
2. **Dedup** — grep `docs-internal/gotcha-index/` for existing entries on the same topic — a trap can sit under a neighbouring tag
3. **If similar exists** → read the detail file, determine which fact is correct, UPDATE the existing file. Never create a second file on the same topic
4. **If new** → create `docs-internal/gotchas/{slug}.md` (format in `docs-internal/DOCS-SCHEMA.md`) + add one index line to `docs-internal/gotcha-index/{topic}.md`
5. **Cross-link** — add `## Related` section in the new file

**Full protocol with examples:** `docs-internal/DOCS-SCHEMA.md` → "Gotcha write protocol"

### Plugin-author skills rule

When a fix — in a plugin (CDEK, Yandex) or the framework — reveals a trap the author of a NEW plugin would hit too, update
`.ai/skills/woodev-plugin-core/` or `woodev-shipping-plugin/` **in the SAME PR as the fix** (checklist line in `SKILL.md`/`traps.md`,
detail in the reference file, citing the plugin file or gotcha slug). A plugin-repo PR cannot edit them: it carries a «skill update
needed: …» note and the coordinator opens the skill PR the same session. Operator, 15.10.2026: «мы же потом снова на эти проблемы
наткнёмся».

---

## 📋 Backlog rule — GitHub Projects, not docs (MANDATORY)

**The single backlog for this repo is GitHub Issues + the board
[Woodev Framework Backlog](https://github.com/users/kalbac/projects/6) (project №6).**

Ideas, bugs, tech debt and deferred findings go there — **not** into `docs-internal/FUTURE-BACKLOG.md`, a `// TODO`, or a chat message.

### Capture — do this immediately, not at session end

Any out-of-scope idea, bug or tech-debt item that surfaces mid-session:

1. `gh issue create --repo kalbac/woodev-plugin-framework` — **title and body in Russian** (the operator
   is the reader; labels, code, commits and docs stay English). Add a type label: `bug`, `enhancement`,
   `idea`, `tech-debt`, `research` or `polish`.
2. Add it to the board — auto-add is **not** enabled, so always do it explicitly:
   `gh project item-add 6 --owner kalbac --url <issue-url>`
3. Set the status (the board has no default that suits us):
   `gh project item-edit 6 --owner kalbac --url <issue-url> --field "Status" --value "<option name>"` (one field per call; gotcha `gh-project-item-edit-takes-the-project-number-and-url-now`)

   | Status | Option id | When |
   |---|---|---|
   | Инбокс | `e765cf18` | The card needs the operator's judgement before anyone builds it |
   | Бэклог | `bdd0cc46` | **Agent-authored and you are confident it should be built** — file it straight here |
   | В работе | `34407388` | You are working on it right now |
   | Готово | `c88618cf` | Set by the board itself when the issue closes |

4. Set «Приоритет» — **no open card may be without one** (operator, 04.09.2026, #644 part 3; the
   board, not this repo's prose, is where priority lives). Same command,
   `--field "Приоритет" --value "<option name>"` (field id `PVTSSF_lAHOAIbGB84BeLaozhhRouo`).

   | Приоритет | Option id | When |
   |---|---|---|
   | Сейчас | `0e25803d` | Being worked right now |
   | Следом | `adb2b45b` | Next in line |
   | Потом | `01c993fc` | Live, not urgent |
   | Ждёт оператора | `30de36ee` | Needs HIS answer before anyone builds it |
   | Заморожено | `3d79482b` | Held on a STATED external condition — name it on the card |
   | После v2 | `94cd2fa3` | Deferred idea; must not compete with anything above |

   Milestones carry release gating, not order: `v2.0 релиз`, `Пилот edostavka`.

A code `TODO` must reference an issue (`// TODO(#123): …`) — never stand alone.

### Lifecycle

- **An agent may move a card itself — but only a card that is already SETTLED.** The discriminator is **whether the card needs the
  operator's answer**, not who wrote it (operator, 27.08.2026, #600):
  - you filed it and it needs no answer from him → move it wherever it belongs, `Бэклог` included.
  - the card is FOR HIM — it asks him to decide something → it stays in `Инбокс` and **he** moves it (to `Бэклог`, or closed as
    `not planned`), even when an AGENT wrote it. Do not simplify this back to "keyed on authorship".
- **Take the card you are working on into `В работе`.** Work driven by a spec/plan rather than a card
  still needs one — create it and move it, so the board reflects reality.
- Close via `Closes #N` in the implementing commit or PR (the board moves the card to `Готово` itself),
  or `gh issue close --reason completed` with a **Russian** comment explaining what closed it.
  Wrong-idea rejections close as `not planned`, also with a Russian comment.
- **Before creating a card from memory or docs, verify against the code first** (one was filed for work already shipped).
- **At session end, audit the board** — unrelated work can resolve a card (Session End step 5).

### `docs-internal/FUTURE-BACKLOG.md` is superseded

Historical record only — **do not add to it**; move anything still live there to an issue.
