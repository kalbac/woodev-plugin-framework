# Planning brief — S1 Shipping module spec + autodev decomposition

> Paste this as the opening prompt of a FRESH planning session.
> Recommended model: **Opus 4.8, high reasoning effort** (this is architecture +
> judgment: the abstraction you pick gets locked into 5+ plugins).
> Your output is a SPEC + a file-disjoint task queue — **not code.** You are the
> Planner role from `docs-internal/autodev-loop-runbook.md`. The autodev loop is the
> executor; it cannot start until you produce a well-formed queue, and the operator
> blesses the direction-level decisions.

## 1. Where this sits

- **S0 (platform split) is DONE** — tag `platform-v2-split-done`, `composer check`
  green, every gate adversarially audited by GPT-5.5.
- **The autodev adversarial loop is built and proven** on branch
  `autodev/loop-bootstrap` (= finished S0 + loop infra + 2 blessed contract guards).
  S1 work continues on that branch.
- **S1 (Shipping universal module) is the real prize**, and per operator decision it
  runs **in the autodev loop**. Audit verdict: shipping is `PLANS.md`'s #1 priority
  (60%+ of WooDev plugins are shipping; the module must be "ideal and maximally
  universal"), currently **~20% complete — a skeleton**. Getting the abstraction
  wrong locks bad patterns into every shipping plugin, so it was deferred correctly —
  now it must be specced carefully, not rushed.

## 2. Read first (grounded paths — do not work from memory)

- `docs-internal/platform-v2-direction-audit-2026-06-03.md` **§7** — sizes the
  shipping module; the authoritative scope list. Also §6.5 (sequencing) and D-5
  (yandex is the second pilot; it carries the PVZ-map reference).
- `PLANS.md` §3.2 (shipping priority + "maximally universal") and §3.5 (box-packer,
  the S2 neighbour — know the seam, don't build it).
- **Current skeleton** `woodev/shipping-method/` (the ~20% that exists — build on it,
  don't reinvent): `class-shipping-plugin.php`, `class-shipping-method.php` +
  `-courier/-pickup/-postal`, `class-shipping-rate.php`,
  `settings/class-shipping-integration.php`, `class-shipping-helper.php`,
  `api/interface-shipping-api.php`, and the **empty** `admin/`, `checkout/`, `api/`,
  `assets/` dirs (≈ the 80% gap). Namespace `Woodev\Framework\Shipping\` (PSR-4).
- **Architectural reference = the mature `payment-gateway` module** (`woodev/payment-gateway/`):
  handlers, REST controllers, admin suite, token/order admin classes. Mirror its
  maturity and layering — that is the target shape for shipping.
- **PVZ-map reference plugin** `plugins-reference/woocommerce-yandex-delivery`
  (read-only). This is the stable implementation of the #1 duplicated piece. Survey
  how it does the map, pickup-point model, checkout modal, filtering, selection
  persistence, address normalization.
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — the format
  for capturing release-blocking contracts. You will produce the **yandex** equivalent.

## 3. What the spec must cover (scope from audit §7)

The "ideal universal shipping module" must absorb what every shipping plugin
hand-rolls. Spec each as an abstraction with a clear public surface:

1. **PVZ / pickup-point map abstraction — FIRST, highest value, highest risk:**
   - pluggable **map provider** (Yandex / Leaflet / …) behind one interface,
   - standard **pickup-point data model**,
   - **warehouse data store**,
   - checkout **modal + balloon templates**,
   - point **filtering** (type / payment method / size),
   - **selection-state persistence** in session + order meta,
   - **address normalization** (DaData).
2. Checkout-field orchestration (custom fields, validation, posted-data handling).
3. Order / tracking / webhook base classes.
4. Admin-UI scaffolding (mirror payment-gateway's admin suite).
5. Rate / method bases — extend the existing skeleton, don't replace it.

The spec MUST: phase PVZ-map first; define each abstraction's interface + extension
points; map what moves from yandex's hand-rolled code into the shared module; and
state the platform-neutral rules carried over from S0 (no WC seams leaking into
neutral bases; namespaced-only new code).

## 4. Capture the data contracts BEFORE any task (release-blocking)

Produce `docs-internal/migration/yandex-data-preservation-checklist.md` mirroring the
edostavka one: shipping method ID(s), instance setting keys, option keys, order/
session meta keys, cron hooks + payloads, AJAX actions, admin slugs, REST namespaces,
log sources, webhook endpoints, EDD/download ID — exact strings from the yandex
reference. These feed `.autodev/INVARIANTS.md` and become guard candidates. Anything
here is "never break."

## 5. Output you must produce

1. **`docs-internal/platform-v2-s1-shipping-spec.md`** — the architecture spec
   (abstractions, interfaces, class list, public surface, phasing, platform-neutral
   rules, the yandex-fixture validation gate à la the edostavka pilot).
2. **`docs-internal/migration/yandex-data-preservation-checklist.md`** — §4.
3. **A file-disjoint task queue** for `.autodev/queue/pending/` — one file per task:
   ```
   id, title, phase (PVZ-map first),
   file_set: [exact paths this task creates/edits],   # the loop serializes overlaps
   depends_on: [task ids],
   contract_zones_touched: [...],                      # from INVARIANTS
   needs_guard: yes/no,                                # if it touches an unguarded contract
   acceptance: [tests that must pass],
   ```
   **File-disjoint discipline is mandatory:** two tasks that edit the same file must
   be merged into one task or made sequential via `depends_on`. The loop runs
   parallel worktrees; overlapping `file_set`s diverge and conflict. Declare
   `file_set` honestly and minimally.

## 6. STOP and get operator blessing on (direction-level — do not just proceed)

These lock patterns into 5+ plugins; they are the operator's call, not yours:
- (a) the **PVZ-map provider abstraction shape** (the interface boundary),
- (b) the **pickup-point data model** + warehouse store schema,
- (c) the **yandex data-contract list** (§4) — confirm completeness against a live install,
- (d) the **phasing/scope cut** (what is in S1 vs deferred to a later S-stage).
Present each as a concrete A/B (or "here's the boundary, approve/adjust"), with the
trade-off and what the audit/PLANS imply. Then wait.

## 7. What NOT to do

- Do NOT write implementation code — spec + checklist + task queue only.
- Do NOT pick the PVZ abstraction unilaterally — §6 is operator-blessed.
- Do NOT produce tasks with overlapping `file_set`s.
- Do NOT touch S0 files or the live `main`/refactor branches; plan on top of
  `autodev/loop-bootstrap`.
- Do NOT invent contract strings — copy exact from the yandex reference.
- Do NOT let "universal" become gold-plating: target what yandex + edostavka actually
  need; the second pilot (yandex) is the concrete validation, not a hypothetical.

## 8. Acceptance criteria

- [ ] Spec covers all five §7 areas, PVZ-map phased first, each with interface + extension points.
- [ ] Yandex data-preservation checklist filled from the reference (exact strings).
- [ ] Task queue is complete, file-disjoint, dependency-ordered, with declared `file_set`s.
- [ ] Tasks touching unguarded contract zones are flagged `needs_guard: yes`.
- [ ] A yandex-shaped fixture validation gate is specified (mirrors the edostavka pilot).
- [ ] The four §6 direction decisions are presented to the operator and blessed before the queue is declared ready.

## Related
- `docs-internal/platform-v2-direction-audit-2026-06-03.md` §7 — shipping sizing (source of scope).
- `docs-internal/platform-v2-program-tracker.md` — live phase state (S1 next, in autodev loop).
- `docs-internal/autodev-loop-runbook.md` — the loop this queue feeds (Planner role, file_set rule).
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — checklist format to mirror.
