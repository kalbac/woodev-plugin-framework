# Setup Wizard — UI design spec (s31)

> Status: **APPROVED** by operator 2026-06-23 (design direction locked via static HTML mockups).
> Scope: visual/UX redesign of the neutral React Setup Wizard (`Woodev\Framework\Setup\Setup_Wizard`, shipped s29 PR #80).
> Companion: s29 architecture spec `2026-06-22-setup-wizard-design.md` (backend/REST/opt-in seam — unchanged here).
> Mockup artifacts (throwaway, NOT committed): `.mockups/{welcome,step,delivery,finish}.html` + `.mockups/style.css`.

## 1. Goal & approach

Rebuild the wizard UI to look like a genuinely modern WP/WC-React onboarding. Operator verdict on the
s30 attempt: "лучше, но не то; легаси выглядит современнее."

**Decision: take the legacy wizard look as the visual base, rebuild it on React components.**
The legacy wizard (`woodev/admin/abstract-plugin-admin-setup-wizard.php`, deleted s29, recovered from
git `f8a4b86^`) was **WooCommerce's own core Setup Wizard** — it enqueued WC core `wc-setup.css` and used
its markup (`#wc-logo`, `<ol class="wc-setup-steps">`, `.wc-setup-content`, `.wc-setup-actions`,
`.wc-wizard-next-steps`). That is the "more modern" identity to reproduce — but recolored to the
**woodev.ru brand cyan** and built with `@wordpress/components` + our SCSS (no WC CSS dependency).

Two reference sources, both read from source (not memory):
- **WC 10.8.1 `wc-setup.css`** (rig container) — layout, stepper, card, finish-list structure.
- **`src/plugins-page/style.scss`** — woodev.ru brand design system (cyan tokens, radii, shadows).

## 2. Design system / tokens

Lifted from `src/plugins-page/style.scss` so the wizard matches the «Плагины» page:

| Token | Value | Use |
|---|---|---|
| `--accent` (primary) | `#06aedd` | stepper line/dots, active step, primary button, toggle on, range, focus, chips, dropdown selection. **Muted cyan** (operator: the brighter `#00c9fd` was too bright). |
| `--accent-strong` | `#0596c4` | hover |
| `--accent-ink` | `#04303d` | dark text/icon on cyan where needed |
| `--accent-soft` | `rgba(6,174,221,.10)` | focus ring, selected dropdown item |
| `--accent-soft-2` | `rgba(6,174,221,.07)` | selected radio row tint |
| bg | `#f1f1f1` | page background |
| card | `#fff` | |
| `--ink` | `#1d2327` | headings, labels |
| text / muted / desc | `#3c434a` / `#646970` / `#757575` | body / secondary / field descriptions |
| border / divider | `#e2e4e7` / `#eef0f2` | group borders / internal dividers |
| radius / radius-sm | `12px` / `8px` | cards / controls |
| base font | `15px` | (operator wanted "чуть крупнее" than 14) |

NOTE on contrast: the «Плагины» page uses **dark ink text on cyan buttons**. In the wizard the muted
`#06aedd` is dark enough that **white button text reads fine** — primary buttons use white text. (If a
future lighter accent is chosen, switch button text to `--accent-ink`.)

## 3. Layout (all screens)

Centered single column, `max-width: 600px`, `text-align:center` wrapper. Order top→bottom:

1. **Brand logo** — centered. `[mark 50px] + [plugin name (23px/700) + "Мастер настройки" subline]`.
   Bigger than s30. Source = `Setup_Wizard::get_header_image_url()` (plugin logo, per-plugin override;
   `''` default → fallback to plugin-name text). **This is the plugin's own logo, NOT woodev.ru brand.**
2. **Stepper** (see §4).
3. **Card** `.wc-setup-content` — white, `border-radius:12px`, soft elevated shadow
   `0 1px 2px rgba(0,0,0,.04), 0 14px 30px rgba(10,30,40,.07)`, padding `2.6em 2.8em`, left-aligned.
   Contains step heading + description + fields + action buttons.
4. **Footer link** below the card — centered grey, exits the wizard (see §6).

Single centered column confirmed (no side panel/illustration).

## 4. Stepper (progress-line + dot)

The WC signature, recolored. `<ol>` of `flex:1` items; each item = label over a `4px` bottom border
(progress line) with a `::before` dot centered on the line at the bottom.

- **upcoming**: line/dot `#d8dadd`, label `#9a9a9a`.
- **done**: line + filled dot `--accent`, label `--accent` (clickable link back).
- **active**: line + ring-dot `--accent`, label `--accent-ink` bold.

**Mandatory finish step:** the terminal **"Готово"** item is always present in the stepper (mirrors the
legacy `<li>Ready!</li>`). On the finish screen it is the `active` item with all prior steps `done`.
→ Framework auto-appends the "Готово" terminal step to the stepper; it is not a registered settings step.

## 5. Card content — fields & controls

### 5.1 Step header
- `h1` step title (25px/600, `--ink`).
- **Step description** — new: `.step-desc` paragraph under the title (14.5px, `--desc`).
  Requires a `description` param on `register_step()` / `register_content_step()`.

### 5.2 Field anatomy
`[label] [tooltip?] / [description?] / [control]`
- **Label** 15px/600 `--ink`; required marker `*` in `#d63638`.
- **Tooltip** — info icon (17px grey circle "i", hover → `--accent-strong`) next to the label, dark
  bubble on hover. Implement with `@wordpress/components` `Tooltip`. Source: a tooltip/help param
  (separate from `description`).
- **Description** — help text under the label (13px italic-free, `--desc`). Source: `Woodev_Setting`
  `description`.

### 5.3 Control-type mapping (render by **control-type**, not just value-type)
s30 bug: `control-field.js` mapped only by setting type (boolean→toggle, options→select, else text) and
**ignored control-type**. Fix: dispatch on control-type. Audit `Woodev_Abstract_Settings::get_control_types()`
/ `get_setting_control_types()` / `Woodev_Control`; add missing types to the Settings-API (PHP) and surface
them via `get_field_schema()` (control-type + min/max/step for range + options for radio/select/multiselect).

| Control-type | Component | Notes |
|---|---|---|
| `text` | `TextControl` | + optional suffix (e.g. ₽) via `.input-row` |
| `select` | **custom dropdown** | NOT a bare native `<select>` (operator). Styled trigger (value + chevron, hover/focus accent) + floating menu (rounded panel, items, hover tint, selected = accent tint + check). Use `@wordpress/components` `CustomSelectControl`/`Dropdown` or equivalent. |
| `radio` | radio group in a **bordered group** | see §5.4; selected row tinted `--accent-soft-2`; **custom radio indicator** = outer ring → ~1px transparent gap → inner dot (NOT a solid filled native dot — operator). Each option may have a sub-description. |
| `number` | number input | + unit suffix |
| `number-with-range` | `RangeControl` | slider (filled track + thumb in accent) + numeric input + suffix |
| `toggle` | `ToggleControl` (WC-pill look) | 40×20 pill, cyan on / grey off, white knob; live in a bordered group |
| `richtext` | rich text editor | toolbar (B / I / list / link) + body |
| `multiselect` | `FormTokenField` (chips) | accent-tinted chips with × ; "Добавить…" input |
| `dimensions` | multi-number row | `Д × Ш × В` + unit (e.g. см) — for package size |

### 5.4 Grouped fields — shared border + dividers
Operator: radio options "слиплись"; grouped fields must be visually unified. A field whose value is a set
of related sub-items renders as **one `.option-group`** = `1px var(--border)`, `border-radius:8px`, with a
`1px var(--divider)` line between rows. Applies to: **radio groups** and **toggle groups** ("Дополнительно").
Single controls (text, select, number, range, richtext, multiselect, dimensions) stay standalone with
generous vertical rhythm (~30px between fields) — operator wanted more "воздух".

## 6. Actions & footer

- **In-card actions** (`.wc-setup-actions`, right-aligned, inside the card):
  - Primary button — cyan bg, white text, `border-radius:8px`, soft cyan shadow. Label per step:
    welcome → "Начать настройку"; settings steps → "Продолжить"; last step → finish label.
  - Secondary "Пропустить" — ghost link (skips **this step** → next). Only on skippable settings steps.
- **Footer link** (below card, centered grey, **exits the wizard**):
  - welcome → "Не сейчас"
  - settings steps → **"Вернуться в Консоль WordPress"** (operator: was duplicated "Пропустить этот шаг";
    in-card "Пропустить" is the skip, footer is the exit).
  - finish → "Вернуться в Консоль WordPress".

## 7. Finish screen

- **Hero**: cyan check circle (64px) + neutral heading.
  **Copy is gender-neutral**: `Плагин «{название}» готов к работе!` (operator: "готова" was feminine).
  Welcome heading likewise name-neutral: `Добро пожаловать!` (name carried by the brand header/description).
- Intro paragraph (centered).
- **Next steps** — bordered rounded list (`.wc-wizard-next-steps` pattern). Each row:
  petite-caps `NEXT STEP`/`ДОКУМЕНТАЦИЯ` heading + title (16.5px/600) + extra line + a secondary button
  ("Открыть зоны", "Читать"). Sourced from `get_finish_actions()` / next-steps data.
- **"Вы также можете:"** row — label + **icon buttons** (⚙ Перейти к настройкам, ★ Оставить отзыв).
  Operator: was plain text links with a spacing bug; now `.btn-icon` (icon + label, bordered, hover accent).

## 8. Behavior

- Client-side step navigation (React state; no page reload between steps) — as already built s30.
- Full-screen render is already in place (`maybe_render_full_screen()` on `admin_init` → standalone HTML
  doc → mount `#woodev-setup-wizard-root` → exit before admin chrome). Keep.
- Finishing marks the wizard complete (existing REST `complete()` on `woodev/v1`).

## 9. Backend touch-points (feed the implementation plan)

1. **Settings-API audit + extension** — enumerate existing control-types; add `radio`, `number`,
   `number-with-range` (min/max/step), `richtext`, `multiselect`, `dimensions` where missing; ensure each
   `Woodev_Setting`/`Woodev_Control` can express: control-type, options, min/max/step, description, tooltip.
2. **`get_field_schema()`** — emit control-type + range bounds + options + description + tooltip per field.
3. **`register_step()` / `register_content_step()`** — add `description` param; surface in `get_bootstrap_data()`.
4. **Stepper** — framework auto-appends terminal "Готово" step.
5. **`get_header_image_url()`** — brand logo already wired (`headerLogoUrl` in bootstrap); render bigger,
   fallback to plugin-name text.

## 10. Frontend touch-points

- Rewrite `src/setup-wizard/style.scss` with the §2 tokens and all component styles from `.mockups/style.css`.
- `control-field.js` → dispatch by control-type (§5.3); add components: custom dropdown, radio-group,
  range, toggle-group, richtext, multiselect (FormTokenField), dimensions.
- `stepper.js` → progress-line+dot styling + terminal finish item.
- `step-view.js` → step description, grouped-field containers.
- finish view → next-steps list + icon-button "также можете" row + neutral copy.
- Classic JSX runtime (createElement/Fragment), `@wordpress/scripts`, LF in `assets/build`.

## 11. Demo / verification (NOT for merge)

Seed the rig-demo fixture (`tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`) with a multi-step
wizard covering ALL control types + descriptions + tooltips + brand logo + finish (production-look), to
browser-verify on rig `:8888`. **Revert before the final PR** (`git checkout` the fixture). PR contains only
framework React/SCSS/PHP + tests.

## 12. Out of scope / deferred

- Mobile/responsive polish (stepper hides < 400px per WC) — basic only this round.
- Save-error inline state styling — minor, follow existing pattern.
- Shipping module settings-page integration (`SHIPPING-PLANS.md` §15) — separate effort.

## 13. Hygiene

`@since 2.0.2`, version not bumped. New code: namespaces + `[]`. After any new framework class →
`php bin/generate-class-map.php`. Public `docs/` untouched. Before PR: revert rig-demo, `composer test:unit`
(expect 782 after revert) + `composer phpcs`, rebuild JS bundle, add/adjust unit tests for new control-types.
Merge: branch → PR → verify EACH CI job = pass + state CLEAN → `--squash --delete-branch` (not `--auto`).
