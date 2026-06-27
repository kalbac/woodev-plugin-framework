# UK-1 — UI-kit foundation + settings rebuilt on kit (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline, batch with checkpoints). Steps use `- [ ]`.
> Spec: `docs-internal/specs/2026-06-27-ui-kit-design.md`. Research: `docs-internal/research/2026-06-27-ui-kit-component-inventory.md`.

**Goal:** Build the shared core UI-kit (tokens + wp-recolor + neutral field components + searchable select + folder tabs/sub-tabs/deep-link + dev-only gallery), and rebuild the Settings page on it, closing all 9 operator review points — without visually regressing the wizard.

**Architecture:** `src/components/` becomes the official kit (core, WP-only). A single `tokens.scss` + `_wp-recolor.scss` are `@use`-imported by every surface's `style.scss`. `control-field` is made neutral (kit classes `woodev-field__*`) and styled in a shared `_field.scss` imported by both settings and wizard. Settings app gains a `tabs-nav` (folder tabs → horizontal sub-tab links → fields) with query-param deep-link. A dev-gated `Woodev → UI Kit` gallery page renders every component/state.

**Tech Stack:** `@wordpress/components` (TabPanel, ComboboxControl, FormTokenField, Card, Tooltip, RangeControl), `@wordpress/scripts` (auto JSX runtime after min-WP 6.6), SCSS, PHP (page registry pattern).

**Min-WP bump:** 6.3 → 6.6 (enables auto JSX runtime; remove `babel.config.js`). Existing `createElement` files keep working; new kit files may use JSX syntax.

---

## File map

**Create:**
- `src/components/tokens.scss` — single token source (cyan `#06aedd` + scale).
- `src/components/_wp-recolor.scss` — recolor native wp-components to brand.
- `src/components/_field.scss` — neutral field anatomy styles (moved out of wizard scss).
- `src/components/field-row.js` — label/control row (WC metrics 182/425).
- `src/components/tabs-nav.js` — folder tabs + horizontal sub-tab links + deep-link.
- `src/ui-kit-gallery/index.js` + `app.js` + `style.scss` — dev-only showcase.
- `woodev/admin/class-ui-kit-gallery-page.php` — dev-gated page registration + enqueue.
- `tests/integration/UiKitGalleryPageTest.php` — gating + registration test.

**Modify:**
- `src/components/control-field.js` — neutral classes `woodev-field__*`; `select` → ComboboxControl; tooltip → wp `Tooltip`.
- `src/settings-page/app.js` — use `tabs-nav`; Card full-width; remove stacked sections.
- `src/settings-page/section-view.js` — render one section's fields via `field-row` (no `<h3>` stack).
- `src/settings-page/style.scss` — `@use` tokens/recolor/field; drop `max-width`, hardcoded cyan, dividers.
- `src/setup-wizard/style.scss` — `@use` tokens/recolor/field; drop duplicated `$wd-*` + field styles now in kit.
- `src/plugins-page/style.scss`, `src/license-page/style.scss` — `@use tokens` only (defer full migration; just unify cyan var so nothing references `#00c9fd`/divergent). *(token unification only; visual migration is UK-2/3.)*
- `babel.config.js` — **delete**.
- `package.json` — add `ui-kit-gallery` to `build` + a `start:gallery`; min-engines unchanged.
- `woodev/class-plugin.php` (or bootstrap min-version constant) — WP min 6.6.
- `woodev/admin/class-admin-pages.php` — register gallery page under dev gate (or do it in the new class hooked from plugin).
- `readme.txt` / docs `Requires at least` — 6.6.
- **Delete** `src/components/dropdown.js` (replaced by ComboboxControl) — after confirming icon imports (`ChevronIcon`/`CheckFilledIcon`) still used elsewhere.

---

## Phase 1 — Token + recolor foundation (no behavior change)

### Task 1.1: tokens.scss
**Files:** Create `src/components/tokens.scss`

- [ ] **Step 1: Write tokens** (single source; SCSS module — consumers `@use './tokens' as wd;`)

```scss
// Woodev UI-kit design tokens. @use this; do not hardcode brand values elsewhere.
// Accent
$accent:        #06aedd;
$accent-strong: #0596c4;
$accent-ink:    #04303d;
$accent-soft:   rgba(6, 174, 221, 0.10);
$accent-soft-2: rgba(6, 174, 221, 0.07);
// Status (kept from license page)
$ok:    #00a32a;
$warn:  #dba617;
$error: #d63638;
// Neutrals / layout
$divider: #eef0f2;
$radius:    12px;
$radius-sm: 8px;
// Field metrics (from PR #89 WC grounding)
$label-col:   182px;
$control-col: 425px;
```

- [ ] **Step 2: Build sanity** — `npm run build:settings` → PASS (file compiles once imported in 1.3).
- [ ] **Step 3: Commit** — `git add src/components/tokens.scss && git commit -m "feat(ui-kit): add shared design tokens (cyan #06aedd)"`

### Task 1.2: _wp-recolor.scss
**Files:** Create `src/components/_wp-recolor.scss`

- [ ] **Step 1: Write recolor partial** — recolor native wp-components to brand. Selectors verified against rig DOM (range/tabpanel/combobox/tokenfield/tooltip).

```scss
@use './tokens' as wd;

// Folder-style active tab (zam.2): replace underline with a folder cap.
.woodev-tabs .components-tab-panel__tabs-item.is-active,
.components-tab-panel__tabs-item.is-active {
	box-shadow: none;
	background: #fff;
	border: 1px solid wd.$divider;
	border-bottom-color: #fff;
	border-radius: wd.$radius-sm wd.$radius-sm 0 0;
	color: wd.$accent-ink;
	&::after { display: none; }
}

// RangeControl (zam.8): brand thumb + filled track.
.components-range-control__track { background-color: wd.$accent !important; }
.components-range-control__thumb-wrapper .components-range-control__thumb,
.components-range-control__wrapper .components-range-control__thumb { background-color: wd.$accent !important; }

// ComboboxControl + FormTokenField focus/selection.
.components-combobox-control__suggestions-container:focus-within { box-shadow: 0 0 0 1px wd.$accent; }
.components-form-token-field__token { background: wd.$accent-soft; }
```

- [ ] **Step 2: Build sanity** after 1.3 import.
- [ ] **Step 3: Commit** — `git commit -m "feat(ui-kit): add wp-components recolor partial"`

### Task 1.3: Wire tokens+recolor into settings scss + verify range fix (zam.8)
**Files:** Modify `src/settings-page/style.scss`

- [ ] **Step 1:** Prepend `@use '../components/tokens' as wd; @use '../components/wp-recolor';` Replace hardcoded `#06aedd` with `wd.$accent`. Keep file minimal for now.
- [ ] **Step 2: Build** `npm run build:settings` → PASS.
- [ ] **Step 3: Rig-verify** the RangeControl renders as a proper slider (operator's «синяя точка» bug) — load `:8888` Woodev→Настройки with a fixture range field; confirm slider + cyan track. *(If still broken, inspect DOM classes and adjust 1.2 selectors — DO NOT assume.)*
- [ ] **Step 4: Commit** — `git commit -m "fix(settings): wire kit tokens+recolor; brand RangeControl (zam.8)"`

---

## Phase 2 — Min-WP 6.6 + drop classic-JSX hack

### Task 2.1: Delete babel.config.js + bump min version
**Files:** Delete `babel.config.js`; modify min-version declaration + `readme.txt`/docs.

- [ ] **Step 1:** Find min-WP declaration. Run: `grep -rn "6\.3\|6\.4\|6\.5" woodev/bootstrap.php woodev/class-plugin.php readme.txt 2>/dev/null` and the loader-definition `wp_version_min`/`backwards_compatible` in fixtures/bootstrap. Update to `6.6`.
- [ ] **Step 2:** `rm babel.config.js`.
- [ ] **Step 3: Full build** `npm run build` → all bundles PASS, byte-stable (no JSX syntax yet → output identical; only proves no breakage).
- [ ] **Step 4: Unit** `composer test:unit` → green.
- [ ] **Step 5: Commit** — `git commit -m "chore: bump min WP to 6.6, drop classic-JSX babel hack"`

---

## Phase 3 — Neutral control-field + field-row + searchable select

### Task 3.1: field-row component
**Files:** Create `src/components/field-row.js`, `src/components/_field.scss`

- [ ] **Step 1:** `_field.scss` — neutral field anatomy (label col 182 / control 425, no dividers — zam.5), `@use './tokens'`. Move the field/tip/range/toggle styles out of wizard scss using neutral `woodev-field__*` classes.
- [ ] **Step 2:** `field-row.js` — renders `[ label(+tooltip) | control ]` row. Tooltip uses wp `Tooltip` (portal, zam.4) not the CSS bubble.

```js
import { createElement } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';
import { InfoIcon } from './icons';

export default function FieldRow( { label, required, tooltip, description, children } ) {
	return createElement( 'div', { className: 'woodev-field' },
		label && createElement( 'div', { className: 'woodev-field__label' },
			label,
			required && createElement( 'span', { className: 'woodev-field__req' }, '*' ),
			tooltip && createElement( Tooltip, { text: tooltip, placement: 'top' },
				createElement( 'span', { className: 'woodev-field__tip', tabIndex: 0, role: 'img', 'aria-label': tooltip },
					createElement( InfoIcon ) ) )
		),
		createElement( 'div', { className: 'woodev-field__control' },
			children,
			description && createElement( 'div', { className: 'woodev-field__desc' }, description )
		)
	);
}
```

- [ ] **Step 3: Commit** — `git commit -m "feat(ui-kit): neutral FieldRow + shared field styles (zam.4,5)"`

### Task 3.2: control-field → neutral classes + ComboboxControl + drop dropdown.js
**Files:** Modify `src/components/control-field.js`; delete `src/components/dropdown.js`

- [ ] **Step 1:** Replace `WizardDropdown` import/usage with `ComboboxControl` for `select`:

```js
import { ComboboxControl } from '@wordpress/components';
// ...
case 'select':
	return withAnatomy( schema, createElement( ComboboxControl, {
		__nextHasNoMarginBottom: true,
		value: value ?? schema.value ?? '',
		options: normalizeOptions( schema.options ),
		onChange,
		allowReset: false,
	} ) );
```

- [ ] **Step 2:** Re-route `renderLabel`/`withAnatomy` to use `FieldRow` (neutral `woodev-field__*`), removing `woodev-setup__*` field classes from control-field. Keep toggle/range/option-group markup but rename to `woodev-field__*`.
- [ ] **Step 3:** `grep -rn "ChevronIcon\|CheckFilledIcon" src/` — confirm other users before deleting; if only dropdown.js → keep icons (still exported). `rm src/components/dropdown.js`.
- [ ] **Step 4: Build** `npm run build` → PASS. `grep -rn "woodev-setup__field\|WizardDropdown\|components/dropdown" src/` → no stale refs.
- [ ] **Step 5: Rig-verify** wizard `:8888` still renders fields correctly (control-field is shared) — select now a searchable combobox; no visual breakage of wizard step fields.
- [ ] **Step 6: Commit** — `git commit -m "feat(ui-kit): neutral control-field + searchable ComboboxControl, drop custom dropdown (zam.6)"`

### Task 3.3: wizard scss uses kit field styles
**Files:** Modify `src/setup-wizard/style.scss`

- [ ] **Step 1:** `@use '../components/tokens' as wd; @use '../components/wp-recolor'; @use '../components/field';` Remove the now-duplicated `$wd-*` vars (point to tokens) and the field/tip/range/toggle blocks now living in `_field.scss`. Keep wizard-only chrome (stepper, full-screen, card).
- [ ] **Step 2: Build + rig-verify** wizard visually intact (cyan from tokens, fields from kit).
- [ ] **Step 3: Commit** — `git commit -m "refactor(wizard): consume kit tokens+field styles (no visual change)"`

---

## Phase 4 — tabs-nav (folder tabs + sub-tabs + deep-link)

### Task 4.1: tabs-nav component
**Files:** Create `src/components/tabs-nav.js`

- [ ] **Step 1:** Component: folder `TabPanel` for providers (level 1) + horizontal sub-tab links for sections (level 2), controlled, with query-param deep-link (`tab`, `section`).

```js
import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';

function readUrl() {
	const p = new URLSearchParams( window.location.search );
	return { tab: p.get( 'tab' ) || '', section: p.get( 'section' ) || '' };
}
function writeUrl( tab, section ) {
	const p = new URLSearchParams( window.location.search );
	p.set( 'tab', tab ); p.set( 'section', section );
	window.history.replaceState( {}, '', `${ window.location.pathname }?${ p.toString() }` );
}

// tabs: [{ id, label, sections:[{id,label}] }]; renderSection(tab, sectionId) => node
export default function TabsNav( { tabs, renderSection, onTabChange } ) {
	const init = readUrl();
	const initialTab = tabs.find( ( t ) => t.id === init.tab ) ? init.tab : tabs[ 0 ].id;
	const [ activeSection, setActiveSection ] = useState( {} ); // { [tabId]: sectionId }

	const sectionFor = ( tab ) =>
		activeSection[ tab.id ] ||
		( tab.id === init.tab && tab.sections.find( ( s ) => s.id === init.section ) ? init.section : tab.sections[ 0 ].id );

	return createElement( TabPanel, {
		className: 'woodev-tabs',
		initialTabName: initialTab,
		tabs: tabs.map( ( t ) => ( { name: t.id, title: t.label } ) ),
		onSelect: ( name ) => { const t = tabs.find( ( x ) => x.id === name ); writeUrl( name, sectionFor( t ) ); onTabChange && onTabChange(); },
	}, ( tabOption ) => {
		const tab = tabs.find( ( t ) => t.id === tabOption.name );
		const current = sectionFor( tab );
		return createElement( Fragment, null,
			tab.sections.length > 1 && createElement( 'nav', { className: 'woodev-subtabs' },
				tab.sections.map( ( s ) => createElement( 'button', {
					key: s.id, type: 'button',
					className: 'woodev-subtabs__link' + ( s.id === current ? ' is-active' : '' ),
					onClick: () => { setActiveSection( ( p ) => ( { ...p, [ tab.id ]: s.id } ) ); writeUrl( tab.id, s.id ); },
				}, s.label ) )
			),
			renderSection( tab, current )
		);
	} );
}
```

- [ ] **Step 2:** Sub-tab styles in `_field.scss` or a small `_tabs.scss`: horizontal links, active = cyan underline; folder tabs recolored via `_wp-recolor.scss`.
- [ ] **Step 3:** On mount, if URL lacked params, `writeUrl(initialTab, firstSection)` so deep-link is shareable (effect).
- [ ] **Step 4: Commit** — `git commit -m "feat(ui-kit): TabsNav — folder tabs + sub-tab links + deep-link (zam.2,3,7)"`

---

## Phase 5 — Settings rebuilt on kit (close 9 points)

### Task 5.1: settings app on TabsNav + Card full-width
**Files:** Modify `src/settings-page/app.js`, `src/settings-page/section-view.js`, `src/settings-page/style.scss`

- [ ] **Step 1:** `app.js` — replace inline `TabPanel` with `TabsNav`; `renderSection(tab, sectionId)` renders that one section's fields (via updated `SectionView`/`field-row`) + the save action. Wrap page in full-width `Card`/`CardBody` (zam.1). Keep edits/save logic intact (per-provider edits, `saveTab`, re-fetch).
- [ ] **Step 2:** `section-view.js` — render a single section's fields (no `<h3>` stack, no per-section divider — zam.5); fields via `control-field`/`field-row`.
- [ ] **Step 3:** `style.scss` — drop `max-width: 920px` (zam.1), drop `__section` dividers (zam.5); rely on kit.
- [ ] **Step 4: Build** `npm run build:settings` → PASS.
- [ ] **Step 5: Rig-verify ALL 9 points** on `:8888` (see checklist below).
- [ ] **Step 6: Commit** — `git commit -m "feat(settings): rebuild on UI-kit; close 9 review points (zam.1-9)"`

---

## Phase 6 — Dev-only component gallery (zam.9 + isolated review)

### Task 6.1: Gallery PHP page (dev-gated) — TDD
**Files:** Create `woodev/admin/class-ui-kit-gallery-page.php`, `tests/integration/UiKitGalleryPageTest.php`; modify class-map + admin wiring.

- [ ] **Step 1: Write failing test** — gallery submenu registered only when `WOODEV_UI_KIT_GALLERY` truthy (or `woodev_ui_kit_gallery` filter); absent otherwise.

```php
public function test_gallery_page_hidden_without_dev_flag(): void {
	$page = new \Woodev\Framework\Admin\Ui_Kit_Gallery_Page();
	$this->assertFalse( $page->is_enabled() );
}
public function test_gallery_page_enabled_via_filter(): void {
	add_filter( 'woodev_ui_kit_gallery', '__return_true' );
	$page = new \Woodev\Framework\Admin\Ui_Kit_Gallery_Page();
	$this->assertTrue( $page->is_enabled() );
}
```

- [ ] **Step 2: Run** `npx wp-env run tests-cli ... --filter UiKitGalleryPageTest` → FAIL (class missing).
- [ ] **Step 3: Implement** the class: `is_enabled()` = `( defined('WOODEV_UI_KIT_GALLERY') && WOODEV_UI_KIT_GALLERY ) || apply_filters('woodev_ui_kit_gallery', false)`; `register_page()` (submenu under `woodev`, cap `manage_options`) + `enqueue_assets()` (mirror settings enqueue, bundle `ui-kit-gallery`). Hook from plugin/admin only when enabled.
- [ ] **Step 4:** `php bin/generate-class-map.php` (no Composer in prod).
- [ ] **Step 5: Run test** → PASS. `composer test:unit` green.
- [ ] **Step 6: Commit** — `git commit -m "feat(ui-kit): dev-gated gallery page (zam.9)"`

### Task 6.2: Gallery React showcase
**Files:** Create `src/ui-kit-gallery/index.js`, `app.js`, `style.scss`; modify `package.json` build.

- [ ] **Step 1:** `app.js` — render every kit element & state: folder tabs + sub-tabs, ComboboxControl (with search), FormTokenField (multiselect), RangeControl, ToggleControl, RadioControl, richtext, TextControl variants, Tooltip (long text near edge to prove portal — zam.4), Card. Use hardcoded demo data.
- [ ] **Step 2:** add `ui-kit-gallery` to `package.json` `build` + `start:gallery`.
- [ ] **Step 3: Build** `npm run build` → PASS (gallery bundle emitted).
- [ ] **Step 4: Rig-verify** `:8888` gallery page (set `define('WOODEV_UI_KIT_GALLERY', true)` in wp-env mu-plugin/config) — all components render, cyan, no console errors.
- [ ] **Step 5: Commit** — `git commit -m "feat(ui-kit): component gallery showcase (zam.9)"`

---

## Phase 7 — Token unification for plugins/license (defer visual migration)

### Task 7.1: point plugins/license cyan at tokens (no layout change)
**Files:** Modify `src/plugins-page/style.scss`, `src/license-page/style.scss`

- [ ] **Step 1:** `@use '../components/tokens' as wd;` replace `#00c9fd`/divergent cyan with `wd.$accent` (plugins). License: leave WP-blue migration to UK-3, but ensure no `#00c9fd` divergence remains anywhere. *(Full license→cyan is UK-3; here only kill the cyan divergence.)*
- [ ] **Step 2: Build** `npm run build` → PASS.
- [ ] **Step 3: Commit** — `git commit -m "refactor(plugins): unify cyan via kit tokens"`

---

## Phase 8 — Fixture, build parity, CI, review, merge

### Task 8.1: Rich demo fixture (all field types)
- [ ] **Step 1:** Extend the test-plugin settings fixture «Карьер» to expose every control type incl. multiselect/richtext/range across ≥2 sections (for sub-tabs) — borrow from PR #89's all-types fixture commit. Keep it as a fixture (revert any rig-only seeding before merge).
- [ ] **Step 2: Commit** — `git commit -m "test(fixture): all-types settings fixture for kit demo"`

### Task 8.2: Build parity + LF + class-map
- [ ] **Step 1:** `npm run build` then verify LF: `git diff --stat` shows no CRLF churn (`.gitattributes` pins build LF). Commit built assets.
- [ ] **Step 2:** `php bin/generate-class-map.php`; `git diff woodev/class-map.php` — committed if changed.
- [ ] **Step 3:** `composer phpcs` + `composer test:unit` green locally (PHPStan = Linux CI).
- [ ] **Step 4: Commit** — `git commit -m "build: rebuild assets + class-map for UI-kit"`

### Task 8.3: Independent critic + push + CI + merge
- [ ] **Step 1:** Independent GPT-5.5 (Codex) critic via `/codex:*` or inline bundle (resolve launch via `codex:setup`); backup `pr-review-toolkit` + `/code-review`. Fix findings; **re-critic own fixes**.
- [ ] **Step 2:** Push branch `feat/uk1-ui-kit-foundation`; open PR; close PR #89 as superseded.
- [ ] **Step 3:** Verify EACH CI job = pass + `mergeStateStatus` CLEAN (separate check, not &&-chained).
- [ ] **Step 4:** `gh pr merge <N> --squash --delete-branch` (never `--auto`). Resync main; push.

---

## 9-point rig-verify checklist (Phase 5 Step 5 + final)

1. [ ] Full-width (no fixed card width)
2. [ ] Folder tabs (not underlined text)
3. [ ] Deep-link opens the right tab+section (`?tab=&section=`)
4. [ ] Tooltip not clipped at viewport edge (portal)
5. [ ] No dividers between options
6. [ ] Select has in-list search; (async source seam present)
7. [ ] Sub-tabs: provider tab → section sub-links (first default)
8. [ ] Range = real slider (cyan), not a broken dot
9. [ ] Gallery shows all field types incl. multiselect/richtext

---

## Self-review notes
- **Spec coverage:** all 7 locked decisions + 9 points mapped to tasks (1.1-8.3). WC-layer = folder only (out of UK-1 scope, spec decision 6 — not a task here).
- **Risk:** control-field is shared → Phase 3 explicitly rig-verifies wizard (3.2 S5, 3.3 S2) to prevent regression of operator-approved wizard.
- **No JS unit tests** (project pattern); PHP gallery gating is TDD'd; everything else proven by build + rig.
- **Visual merge:** operator granted full autonomy to merge if CI green + critic clean + visually confident; gallery + all-types fixture seeded for his morning review.

## Related
- spec `docs-internal/specs/2026-06-27-ui-kit-design.md`
- research `docs-internal/research/2026-06-27-ui-kit-component-inventory.md`
- gotchas: `wp-scripts-jsx-runtime-wp66` (becomes stale), `wp-scripts-css-enqueue-version-by-mtime`, `build-artifacts-eol-lf-windows-parity`, `serena-replace-content-eol-flip`, `russian-source-i18n-plural-n`, `classmap-autoload-breaks-class-exists-once-guard`
