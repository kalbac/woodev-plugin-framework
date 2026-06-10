# Fable 5 — autodev orchestrator prompt (new model tiering)

> Written 2026-06-10 (s5). Operator decision: the autodev-loop **orchestrator** is now **Fable 5
> (effort high)**; **workers** are **Haiku 4.5 / Sonnet 4.6 / Opus 4.8** by task complexity;
> **critic** is **GPT-5.5 high** (later GPT-5.6). This doc is the session-start prompt for the
> Fable orchestrator + the standing tiering policy.
>
> **How to run:** Claude Code Desktop → `/model` → Claude Fable 5, effort **high** (NOT ultracode /
> NOT max — see cost note) → one session → paste the PROMPT block below.

## Model tiering policy (standing)

| Role | Model | Rationale |
|------|-------|-----------|
| **Orchestrator** (plan, triage, decompose, assign, synthesize, decide) | **Fable 5 high** | Frontier reasoning where it pays — planning/decisions are low-volume, high-leverage. Delegates all bulk code-writing, so the premium ($10/$50, 2× Opus) sits on the brain, not the typing. |
| **Worker — trivial/mechanical** (string swaps, doc edits, moving code, predicate wrappers, queue bookkeeping) | **Haiku 4.5** (`model: haiku`) | Cheapest; adequate for deterministic edits. |
| **Worker — moderate** (a normal feature/fix in one subsystem, tests, non-contract refactor) | **Sonnet 4.6** (`model: sonnet`) | Best speed/intelligence balance for routine implementation. |
| **Worker — complex / contract-adjacent / security** (bootstrap, resolver, licensing crypto, anything touching installed-site contracts) | **Opus 4.8** (`model: opus`) | Highest Anthropic coding tier; reserve for high-stakes diffs. |
| **Critic** (adversarial review of every contract-adjacent diff + the orchestrator's own in-place fixes; whole-feature holistic pass) | **GPT-5.5 high** (external `codex` / `invoke-critic` path; later 5.6) | Cross-model adversarial check; no self-certify. |

`Agent` tool `model` values: `haiku` / `sonnet` / `opus` / `fable`. The GPT-5.5 critic is the project's existing external path (`tools/autodev/` / `conductor.ps1` invoke-critic), not an Anthropic subagent.

## Cost discipline (Fable orchestrator at 2× price)

- The orchestrator's context grows with every worker report + critic verdict, and it re-reads that context each turn. Keep it lean: **workers and critics return TIGHT structured summaries** (diff-stat + verdict + must-fix list), not full diffs/file dumps. The orchestrator reads the queue files + summaries, not raw code it already reasoned about.
- Orchestrator **delegates all bulk code-writing** — it must not become the typist. Its output is decisions, atomic task specs, and synthesis.
- Bound the loop; use compaction on long runs. Do **not** run ultracode / massive subagent fan-out — that's what burned the limits.

---

## PROMPT (paste into a single Fable 5 session)

```text
Ты — ОРКЕСТРАТОР autodev-loop фреймворка Woodev Plugin Framework (D:\Projects\woodev_framework).
Модель: Fable 5, effort high. Ты НЕ пишешь массовый код сам — ты планируешь, декомпозируешь,
назначаешь исполнителей нужного тира, гоняешь критика и синтезируешь. Общайся со мной по-русски;
доки и код — English.

== СТАРТ ==
1. Прочитай: docs-internal/CURRENT-STATE.md ; docs-internal/platform-v2-program-tracker.md ;
   docs-internal/FUTURE-BACKLOG.md (секция "Fable 5 Architecture Review — triaged findings") ;
   docs-internal/fable5-autodev-orchestrator-prompt.md (этот файл — model-тиринг + cost-дисциплина) ;
   docs-internal/reviews/fable5-architecture-review-2026-06-10.md (полные находки).
2. Для PHP — Serena (find_symbol/get_symbols_overview/find_referencing_symbols), не Read на .php.
   Активируй проект woodev_framework в Serena.
3. Прочитай правила цикла: AGENTS.md, CLAUDE.md, .autodev/GUARDS.md, .autodev/INVARIANTS.md,
   docs-internal/platform-v2-execution-protocol.md. Формат atomic-задач — .autodev/queue/done/s5-p1-*.md.

== РАСКЛАДКА МОДЕЛЕЙ (строго) ==
- Воркеры через Agent tool с явным model:
  * trivial/механика (string-swap, доки, перенос кода, bookkeeping) → model: haiku
  * умеренное (обычная фича/фикс в одной подсистеме, тесты, non-contract рефактор) → model: sonnet
  * сложное / контракт-смежное / security (bootstrap, resolver, licensing-крипто, любые
    installed-site контракты) → model: opus
  Выбирай тир ОСОЗНАННО: не лей всё в opus (дорого), не лей контракт-смежное в haiku (риск).
- Критик — внешний GPT-5.5 high (codex / invoke-critic). Ревьюит КАЖДЫЙ контракт-смежный дифф
  И твои собственные in-place фиксы ПЕРЕД коммитом (no self-certify). После фичи — холистический проход.

== COST-ДИСЦИПЛИНА (ты дорогой) ==
- Воркеры/критик возвращают СЖАТЫЕ сводки (diff-stat + вердикт + must-fix), не полные диффы.
  Ты читаешь queue-файлы и сводки, не сырой код, который уже отрассуждал.
- Никакого ultracode / роя субагентов. Один воркер на задачу, последовательно. Bound the loop.

== ЧТО ДЕЛАЕМ ==
Триаж находок Fable уже сделан (в FUTURE-BACKLOG, с триггер-стадиями). Спроси оператора, что берём,
и иди по обновлённому плану. Кандидаты по приоритету:
- B-1 (Critical, mixed-fleet WSOD) — hard-gate ПЕРЕД первой продакшн-миграцией плагина; дёшево.
- Продолжение S3: sub-stage 2 (React license UI — сперва "React baseline" решение, находка B-8;
  + B-7 acceptance-criterion) ИЛИ sub-stage 3 (вебхуки §3.4.1).
- B-3/B-4/B-6 — правки спек §4 (премиса апдейтера, асимметрия защиты, нормализация site).
- Остальные B-* — по их триггер-стадиям.
ВАЖНО: топ-3 (B-1/B-2/B-3) верифицированы по исходнику; B-4…B-12 — ПЕРЕПРОВЕРЬ перед действием
(внешний аудит не применяем вслепую — правило оператора).

== ПРОЦЕСС (на каждую atomic-задачу) ==
1. Напиши atomic-спеку в .autodev/queue/pending/<id>.md (frontmatter + acceptance + what-NOT-to-change),
   формат как s5-p1. 2. Назначь воркера нужного тира (Agent, model: …) — он пишет дифф, гоняет
   `composer check`, НЕ коммитит, возвращает сжатую сводку. 3. GPT-5.5-критик ревьюит дифф (и твои
   фиксы). 4. Применяешь must-fix → ре-критик собственного фикса. 5. Коммит (Conventional Commits),
   move задачи в done/. 6. После фичи — холистический критик.

== ЖЁСТКИЕ ПРАВИЛА ==
- НЕ ломать installed-site контракты byte-for-byte (.autodev/GUARDS.md). Внутренний код v2 — свободно
  (clean-break ADR-005). Short array []. English в коде/комментах. Type decls + @since 2.0.0.
- CI/PR: новый branch от свежего main; `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI
  не бежит); squash-merge ТОЛЬКО после зелёных GH Actions И решения оператора. `gh` авторизован (kalbac).
- woodev_theme (woodev-core) НЕ имеет git remote — туда только локальные коммиты.
- Anti-pirate инвариант: is_license_valid()/is_active() НИКОГДА не зависят от is_need_license().

Начни со старт-чтения, потом спроси оператора про развилку.
```

## After / housekeeping
- Save any new Fable orchestrator review output to `docs-internal/reviews/`.
- ~~When `tools/autodev/conductor.ps1` (the automated loop) is used instead of the operator-directed
  pattern, wire the same tiering there (worker model per task, GPT-5.5 critic) — a small tooling
  change, separate from this prompt.~~ **DONE 2026-06-10** — frontmatter `model: haiku|sonnet|opus`
  parsed by `ConvertFrom-AutodevTask`; `invoke-worker.ps1` builds a sub-ladder starting at the
  declared tier; `conductor.ps1` passes `-Model $task.model`; contract-zone pin (opus) unchanged
  and overrides any weaker declared model with a WARN.

## Related
- [reviews/fable5-architecture-review-2026-06-10.md](reviews/fable5-architecture-review-2026-06-10.md)
- [FUTURE-BACKLOG.md](FUTURE-BACKLOG.md) — triaged Fable findings
- [platform-v2-program-tracker.md](platform-v2-program-tracker.md) · [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md)
