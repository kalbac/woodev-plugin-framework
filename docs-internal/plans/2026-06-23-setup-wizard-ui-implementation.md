# Setup Wizard UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans. Steps use `- [ ]` checkboxes. **Branch:** `feat/setup-wizard-fullscreen`.

**Goal:** Rebuild the Setup Wizard UI to the operator-approved design (legacy WC-setup look, woodev.ru cyan,
on `@wordpress/components`), with control-type-driven field rendering, step/option descriptions, tooltips,
all control types, a mandatory finish step, and the modern finish screen.

**Architecture:** Extend the Settings-API to carry control-type + min/max/step + tooltip; emit them in the
wizard's `get_field_schema()`; rewrite the React shell (dispatch by control-type) and SCSS to the approved
mockup. Full-screen render (`maybe_render_full_screen`) stays as-is.

**Tech Stack:** PHP 7.4+ (namespaced `Woodev\Framework\*`), `@wordpress/components` + `@wordpress/element`
(classic JSX runtime), SCSS via `@wordpress/scripts`, Brain Monkey unit tests.

**Design source of truth:** spec `docs-internal/specs/2026-06-23-setup-wizard-ui-design.md` + approved mockup
`.mockups/{welcome,step,delivery,finish}.html` + `.mockups/style.css` (exact CSS/markup to port; deleted
before PR per spec §13).

**Reference (exact current line numbers, branch `feat/setup-wizard-fullscreen`):**
- `woodev/settings-api/class-control.php` — type consts L13–46; props `setting_id/type/name/description/options` L48–61; `set_type/set_name/set_description/set_options` L136–202. **No min/max/step/tooltip.**
- `woodev/settings-api/class-setting.php` — `get_control()` returns the `Woodev_Control`.
- `woodev/settings-api/abstract-class-settings.php` — `get_control_types()` L427–451; `register_control($setting_id,$type,$args[name,description,options])` L126–175.
- `woodev/setup/class-setup-wizard.php` — `register_step($id,$label,$setting_ids,$on_save)` L105–107; `register_content_step($id,$label,$content)` L119–121; `get_bootstrap_data()` L531–579 (step shape L560–566); `get_field_schema()` L589–606 (emits type/name/options/value only); `get_finish_actions()` L615–625.
- `woodev/setup/class-step.php` — `Step::settings()/content()`, `TYPE_SETTINGS/TYPE_CONTENT`, getters; **no description, no setting-level metadata.**
- React `src/setup-wizard/`: `app.js` (state machine + `renderFinish`), `stepper.js` (numbered), `step-view.js` (h2 + fields/content), `control-field.js` (type-based dispatch), `icons.js` (CheckIcon only), `style.scss` (`$wd-accent:#7f54b3`), `rest.js`, `index.js`, `progress.js` (legacy s29 leftover — unused, delete in Task C2).
- Build: `babel.config.js` classic runtime (pragma `createElement`/`Fragment`); `npm run build:setup` → `woodev/assets/build/setup-wizard/`.

---

## Design tokens (apply everywhere) — from `src/plugins-page/style.scss`

```
$wd-accent:        #06aedd;   // primary (muted cyan)
$wd-accent-strong: #0596c4;   // hover
$wd-accent-ink:    #04303d;
$wd-accent-soft:   rgba(6,174,221,.10);
$wd-accent-soft-2: rgba(6,174,221,.07);
$wd-bg:#f1f1f1; $wd-surface:#fff; $wd-ink:#1d2327; $wd-text:#3c434a;
$wd-muted:#646970; $wd-desc:#757575; $wd-border:#e2e4e7; $wd-divider:#eef0f2;
$wd-radius:12px; $wd-radius-sm:8px; base font 15px;
```
Primary button: cyan bg + **white** text (muted cyan is dark enough). Full component values: `.mockups/style.css`.

---

## PHASE A — Settings-API: control-type + metadata (PHP, TDD)

### Task A1: Add control-type constants + min/max/step/tooltip to `Woodev_Control`

**Files:**
- Modify: `woodev/settings-api/class-control.php`
- Test: `tests/unit/SettingsApi/ControlTest.php` (create if absent; else add cases)

- [ ] **Step 1 — Failing test** (`tests/unit/SettingsApi/ControlTest.php`)
```php
<?php
use PHPUnit\Framework\TestCase;

final class ControlTest extends TestCase {
	public function test_new_control_type_constants_exist(): void {
		$this->assertSame( 'richtext', \Woodev_Control::TYPE_RICHTEXT );
		$this->assertSame( 'multiselect', \Woodev_Control::TYPE_MULTISELECT );
		$this->assertSame( 'toggle', \Woodev_Control::TYPE_TOGGLE );
	}

	public function test_range_bounds_and_tooltip_roundtrip(): void {
		$c = new \Woodev_Control();
		$c->set_min( 0 ); $c->set_max( 100 ); $c->set_step( 5 );
		$c->set_tooltip( 'Help text' );
		$this->assertSame( 0.0, $c->get_min() );
		$this->assertSame( 100.0, $c->get_max() );
		$this->assertSame( 5.0, $c->get_step() );
		$this->assertSame( 'Help text', $c->get_tooltip() );
	}
}
```

- [ ] **Step 2 — Run, expect FAIL**
Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ControlTest.php`
Expected: FAIL (undefined constants / methods).

- [ ] **Step 3 — Implement** in `class-control.php`:
  - After `const TYPE_RANGE = 'range';` (L46) add:
```php
	const TYPE_TOGGLE      = 'toggle';
	const TYPE_RICHTEXT    = 'richtext';
	const TYPE_MULTISELECT = 'multiselect';
```
  - After `$options` property (L61) add:
```php
	/** @var float|null minimum (range/number). */
	protected $min = null;
	/** @var float|null maximum (range/number). */
	protected $max = null;
	/** @var float|null step (range/number). */
	protected $step = null;
	/** @var string tooltip/help shown beside the label. */
	protected $tooltip = '';
```
  - Add setters/getters (match existing style; cast numeric to float, tooltip to string):
```php
	public function set_min( $value ): void { $this->min = is_numeric( $value ) ? (float) $value : null; }
	public function set_max( $value ): void { $this->max = is_numeric( $value ) ? (float) $value : null; }
	public function set_step( $value ): void { $this->step = is_numeric( $value ) ? (float) $value : null; }
	public function set_tooltip( string $value ): void { $this->tooltip = $value; }
	public function get_min() { return $this->min; }
	public function get_max() { return $this->max; }
	public function get_step() { return $this->step; }
	public function get_tooltip(): string { return $this->tooltip; }
```
  (Confirm `get_type()`/`get_description()`/`get_options()` getters exist; if not, add them — the schema in B1 needs `get_type()`.)

- [ ] **Step 4 — Run, expect PASS**
Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ControlTest.php`

- [ ] **Step 5 — Commit**
```bash
git add woodev/settings-api/class-control.php tests/unit/SettingsApi/ControlTest.php
git commit -m "feat(settings): control-type consts (toggle/richtext/multiselect) + min/max/step/tooltip"
```

### Task A2: Register new types in `get_control_types()` + accept metadata in `register_control()`

**Files:**
- Modify: `woodev/settings-api/abstract-class-settings.php` (`get_control_types()` L427–451; `register_control()` L126–175)
- Test: `tests/unit/SettingsApi/SettingsControlTypesTest.php` (extend existing settings test if present)

- [ ] **Step 1 — Failing test**
```php
public function test_get_control_types_includes_new_types(): void {
	$types = $this->settings->get_control_types(); // concrete test double over Woodev_Abstract_Settings
	foreach ( [ 'toggle', 'richtext', 'multiselect' ] as $t ) {
		$this->assertContains( $t, $types );
	}
}
public function test_register_control_persists_min_max_step_tooltip(): void {
	$this->settings->register_setting( 'markup', 'float', [ 'name' => 'Markup' ] );
	$this->settings->register_control( 'markup', 'range', [ 'min' => 0, 'max' => 100, 'step' => 5, 'tooltip' => 'help' ] );
	$control = $this->settings->get_setting( 'markup' )->get_control();
	$this->assertSame( 100.0, $control->get_max() );
	$this->assertSame( 'help', $control->get_tooltip() );
}
```

- [ ] **Step 2 — Run, expect FAIL**
Run: `./vendor/bin/phpunit tests/unit/SettingsApi/SettingsControlTypesTest.php`

- [ ] **Step 3 — Implement**
  - In `get_control_types()` add the three new constants to the `$control_types` array (L428–440).
  - In `register_control()` (after it builds the `Woodev_Control` and applies name/description/options, ~L150–170) add:
```php
		if ( isset( $args['min'] ) )     { $control->set_min( $args['min'] ); }
		if ( isset( $args['max'] ) )     { $control->set_max( $args['max'] ); }
		if ( isset( $args['step'] ) )    { $control->set_step( $args['step'] ); }
		if ( isset( $args['tooltip'] ) ) { $control->set_tooltip( (string) $args['tooltip'] ); }
```

- [ ] **Step 4 — Run, expect PASS** · **Step 5 — Commit**
```bash
git add woodev/settings-api/abstract-class-settings.php tests/unit/SettingsApi/SettingsControlTypesTest.php
git commit -m "feat(settings): expose new control types + min/max/step/tooltip via register_control"
```

---

## PHASE B — Wizard PHP: schema + steps + finish

### Task B1: Emit control-type + description + tooltip + range bounds in `get_field_schema()`

**Files:** Modify `woodev/setup/class-setup-wizard.php` L589–606 · Test `tests/unit/Setup/SetupWizardSchemaTest.php`

- [ ] **Step 1 — Failing test** — assert a setting whose control is `range` with min/max/step/tooltip emits
`controlType`, `min`, `max`, `step`, `tooltip`, `description` keys (in addition to type/name/options/value).
(Mock `get_settings_handler()` returning a handler with one `Woodev_Setting` carrying a `Woodev_Control`.)

- [ ] **Step 2 — Run, expect FAIL.**

- [ ] **Step 3 — Implement** — replace the schema body (L597–602) with:
```php
		$schema = [];
		foreach ( $handler->get_settings() as $setting ) {
			$control = $setting->get_control();
			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $handler->get_value( $setting->get_id() ),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control ? $control->get_description() : ( method_exists( $setting, 'get_description' ) ? $setting->get_description() : '' ),
				'tooltip'     => $control && method_exists( $control, 'get_tooltip' ) ? $control->get_tooltip() : '',
			];
			if ( $control && null !== $control->get_min() )  { $entry['min']  = $control->get_min(); }
			if ( $control && null !== $control->get_max() )  { $entry['max']  = $control->get_max(); }
			if ( $control && null !== $control->get_step() ) { $entry['step'] = $control->get_step(); }
			$schema[ $setting->get_id() ] = $entry;
		}
```

- [ ] **Step 4 — Run PASS** · **Step 5 — Commit** `feat(setup): emit controlType/description/tooltip/range bounds in field schema`

### Task B2: Step `description` + auto-appended terminal finish step

**Files:** Modify `woodev/setup/class-step.php` (add `$description` + getter, both factories accept it),
`class-setup-wizard.php` (`register_step`/`register_content_step` signatures L105–121; `get_bootstrap_data` step shape L560–566; append a synthetic finish step descriptor). Test `SetupWizardStepsTest.php`.

- [ ] **Step 1 — Failing test** — `register_step('connection','Подключение',['api_key'], null, 'Опишите доступ')`
results in a bootstrap step with `description === 'Опишите доступ'`; and `get_bootstrap_data()['steps']` last
entry is `['id'=>'finish','type'=>'finish','label'=>'Готово', ...]` auto-appended.

- [ ] **Step 2 — Run FAIL.**

- [ ] **Step 3 — Implement**
  - `class-step.php`: add `protected string $description = '';` + `get_description()`; `settings()`/`content()`
    factories take a trailing `string $description = ''`; store it.
  - `register_step()` → `(string $id, string $label, array $setting_ids, ?callable $on_save = null, string $description = '')` passing `$description` to `Step::settings(...)`.
  - `register_content_step()` → add trailing `string $description = ''` → `Step::content(...)`.
  - `get_bootstrap_data()`: add `'description' => $step->get_description(),` to the step array (L560–566); after the
    `foreach`, append:
```php
		$steps[] = [
			'id'          => 'finish',
			'label'       => __( 'Готово', 'woodev-plugin-framework' ),
			'type'        => 'finish',
			'description' => '',
			'fields'      => [],
			'content'     => '',
		];
```
  (React treats `type==='finish'` as the terminal/finish screen — see Task C4.)

- [ ] **Step 4 — Run PASS** · **Step 5 — Commit** `feat(setup): step descriptions + auto-appended terminal finish step`

### Task B3: Finish data — next-step cards + secondary "also" actions

**Files:** Modify `class-setup-wizard.php` `get_finish_actions()` L615–625; add `get_finish_secondary_actions()`;
emit both in `get_bootstrap_data()` return (L569–578). Test `SetupWizardFinishTest.php`.

- [ ] **Step 1 — Failing test** — `get_finish_actions()` returns items shaped
`['heading'=>..,'title'=>..,'description'=>..,'actionLabel'=>..,'url'=>..]`; bootstrap includes
`finishActions` (cards) and `finishSecondaryActions` (`['label'=>..,'icon'=>'settings|review','url'=>..]`).

- [ ] **Step 2 — Run FAIL.**

- [ ] **Step 3 — Implement**
  - Rewrite `get_finish_actions()` to return next-step cards (default: a Documentation card when
    `get_documentation_url()` is set):
```php
	protected function get_finish_actions(): array {
		$actions = [];
		if ( $this->plugin->get_documentation_url() ) {
			$actions[] = [
				'heading'     => __( 'Документация', 'woodev-plugin-framework' ),
				'title'       => __( 'Тонкая настройка', 'woodev-plugin-framework' ),
				'description' => __( 'Подробнее о возможностях плагина.', 'woodev-plugin-framework' ),
				'actionLabel' => __( 'Читать', 'woodev-plugin-framework' ),
				'url'         => esc_url_raw( $this->plugin->get_documentation_url() ),
			];
		}
		return $actions;
	}

	protected function get_finish_secondary_actions(): array {
		$actions = [];
		if ( method_exists( $this->plugin, 'get_settings_url' ) && $this->plugin->get_settings_url() ) {
			$actions[] = [ 'label' => __( 'Перейти к настройкам', 'woodev-plugin-framework' ), 'icon' => 'settings', 'url' => esc_url_raw( $this->plugin->get_settings_url() ) ];
		}
		if ( method_exists( $this->plugin, 'get_reviews_url' ) && $this->plugin->get_reviews_url() ) {
			$actions[] = [ 'label' => __( 'Оставить отзыв', 'woodev-plugin-framework' ), 'icon' => 'review', 'url' => esc_url_raw( $this->plugin->get_reviews_url() ) ];
		}
		return $actions;
	}
```
  - In `get_bootstrap_data()` return array add: `'finishSecondaryActions' => $this->get_finish_secondary_actions(),`.

- [ ] **Step 4 — Run PASS** · **Step 5 — Commit** `feat(setup): finish next-step cards + secondary actions`

---

## PHASE C — React shell (port the approved mockup)

> All JS uses classic runtime: `import { createElement, Fragment } from '@wordpress/element'`. Verify visually
> on rig after each task (Phase D). Port exact markup/classes/styling from `.mockups/`.

### Task C1: `control-field.js` — dispatch by `controlType`, add all controls

**Files:** Rewrite `src/setup-wizard/control-field.js`.

- [ ] **Step 1 — Implement** — dispatch on `schema.controlType` (fallback to legacy type/options inference when
absent, so existing plugins keep working). Render field anatomy `[label + tooltip] / [description] / [control]`.
Components by control-type:
  - `text`/`email`/`password`/`number` → `TextControl` (`type` set accordingly) + optional unit suffix via a
    `.woodev-setup__input-row` wrapper.
  - `toggle` (or setting `type==='boolean'`) → `ToggleControl` (styled WC-pill via SCSS); in a grouped container if part of a group (grouping handled in step-view).
  - `select` → custom dropdown component (Task C1b) — NOT bare `SelectControl`.
  - `radio` → `RadioControl` rendered inside `.woodev-setup__option-group` (Task C3 wraps), custom indicator via SCSS.
  - `range`/`number-with-range` → `RangeControl` ({ min: schema.min ?? 0, max: schema.max ?? 100, step: schema.step ?? 1 }) + numeric input + suffix.
  - `richtext` → minimal rich-text editor. MVP: `TextareaControl` with a toolbar shell matching the mockup (full
    TinyMCE/`@wordpress/block-editor` is out of scope this round — note in spec §12); upgrade later.
  - `multiselect` (or `is_multi`) → `FormTokenField` (chips) mapped to/from option keys.
  - `dimensions` → three number inputs (`Д × Ш × В`) + unit; value is `{l,w,h}`.
- Label renders required `*` when `schema.required`; tooltip renders `Tooltip` (Task C6 InfoIcon) when
`schema.tooltip`; description renders under the label when `schema.description`.

- [ ] **Step 2 — Build** `npm run build:setup` → expect success, 0 errors.
- [ ] **Step 3 — Commit** `feat(setup): control-field dispatch by control-type + all control types`

### Task C1b: custom Dropdown component

**Files:** Create `src/setup-wizard/dropdown.js`.
- [ ] Implement a controlled dropdown matching `.mockups/style.css` `.dropdown*` (trigger = value + chevron;
floating menu = items, hover tint, selected = accent tint + check). Use `@wordpress/components` `Dropdown` +
`MenuGroup`/`MenuItem` or a small custom popover; keyboard accessible. Build + commit
`feat(setup): custom dropdown control`.

### Task C2: `stepper.js` — progress-line + dot, terminal finish, shown on finish

**Files:** Rewrite `src/setup-wizard/stepper.js`; delete unused `src/setup-wizard/progress.js`.
- [ ] Replace numbered-marker markup with the `.mockups` stepper: `<ol class="woodev-setup__steps">` of
`<li class="is-{done|active|upcoming}">label</li>` (label over a 4px progress line + `::before` dot; styling in
C5). Include the terminal `finish` step (it comes from bootstrap now). The stepper must render on the finish
screen too (all prior `done`, `finish` `active`).
- [ ] Build + commit `feat(setup): progress-line stepper with terminal finish step`.

### Task C3: `step-view.js` — step description + grouped fields

**Files:** Modify `src/setup-wizard/step-view.js`.
- [ ] Render `h1.woodev-setup__step-title` (step.label) + `p.woodev-setup__step-desc` (step.description) when
present. Wrap consecutive `radio` options and consecutive `toggle` fields each in a single
`.woodev-setup__option-group` (shared border + dividers) per spec §5.4; standalone controls render with
`.woodev-setup__field` spacing.
- [ ] Build + commit `feat(setup): step description + grouped option containers`.

### Task C4: `app.js` — skip semantics, footer exit, brand header, finish screen

**Files:** Modify `src/setup-wizard/app.js`.
- [ ] **Skip = skip THIS step** → advance to next without saving (not exit). Rename current exit behavior to a
**footer link** below the card: welcome → "Не сейчас"; settings/finish → "Вернуться в Консоль WordPress"
(calls `complete('skipped')` then navigates to admin/dashboard URL from bootstrap, or `window.location`).
- [ ] **Brand header** — bigger: `headerLogoUrl` img (taller) OR mark+`pluginName`+"Мастер настройки" subline
(match `.mockups` `.brand`).
- [ ] **Finish screen** = the `type==='finish'` step: cyan check hero + neutral title
`sprintf( __( 'Плагин «%s» готов к работе!' ), pluginName )` + intro + **next-step cards** (from
`finishActions`: heading/title/description + secondary button) + **"Вы также можете:"** row of icon buttons
(from `finishSecondaryActions`, icon by `action.icon` → gear/star via icons.js) + footer exit link. Render the
stepper above it.
- [ ] Primary button labels: welcome → "Начать настройку"; settings → "Продолжить"; last settings step → advance
to finish.
- [ ] Build + commit `feat(setup): skip-step vs exit, brand header, modern finish screen`.

### Task C5: `style.scss` — port the approved mockup

**Files:** Rewrite `src/setup-wizard/style.scss`.
- [ ] Replace `$wd-accent:#7f54b3` block with the cyan tokens above. Port every component style from
`.mockups/style.css` into BEM scoped under `.woodev-setup` (and `body.woodev-setup-wizard` for page bg):
brand logo (bigger), stepper (progress-line+dot), card (radius 12 + soft elevated shadow), step title +
`__step-desc`, `__field` rhythm, `__field-label`/`__field-desc`/tooltip icon, custom radio (ring→gap→dot),
custom dropdown + menu, multiselect chips, range, WC-pill toggle, `__option-group` (border + dividers),
actions (primary cyan/white + ghost skip), footer link, finish (check hero, next-steps list, `.btn-icon`
"also" row). Keep the existing `.components-*` accent overrides but switch to cyan. Keep the `@media(max-width:600px)` collapse.
- [ ] Build + commit `style(setup): port approved cyan WC-onboarding styling`.

### Task C6: `icons.js` — add icons

**Files:** Modify `src/setup-wizard/icons.js`.
- [ ] Add `InfoIcon` (tooltip "i"), `ChevronIcon` (dropdown), `GearIcon` (settings), `StarIcon` (review). Keep
`CheckIcon`. Build + commit `feat(setup): wizard icons (info/chevron/gear/star)`.

---

## PHASE D — Demo, verify, gate

### Task D1: Seed rig-demo fixture (NOT for merge)
**Files:** Modify `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`.
- [ ] Extend the demo `Woodev_Test_Settings` + wizard to register: welcome (content + description + features),
a "Подключение" step and a "Доставка" step covering **every** control type (text+tooltip, select/dropdown,
radio group, number+suffix, range w/ min/max/step, toggle group, richtext, multiselect, dimensions) with
descriptions/tooltips; set a `get_header_image_url()` + finish actions. Production-look copy (mirror `.mockups`).
- [ ] Commit (on branch, will be reverted) `test(setup): rig-demo seed covering all control types [revert before PR]`.

### Task D2: Browser-verify on rig `:8888`
- [ ] `npx wp-env start` if down. Navigate
`http://localhost:8888/wp-admin/admin.php?page=woodev-woodev-test-plugin-setup` via Playwright. Verify all
4 screens (welcome/подключение/доставка/готово) match the approved mockup; 0 console errors; every control
renders + is interactive; tooltip, range, dropdown-open, multiselect chips, grouped borders, neutral finish
copy, footer "Вернуться в Консоль WordPress", icon "also" buttons. Screenshot each; fix gaps; re-verify.

### Task D3: Gate
- [ ] `php bin/generate-class-map.php` (only if a new framework PHP class file was added — none expected; the new
controls are JS + existing PHP classes extended).
- [ ] `composer phpcs` → clean.
- [ ] `composer test:unit` → green (new tests added; count will exceed 782 on branch + revert math).
- [ ] (PHPStan crashes on Windows — rely on Linux CI gate.)

---

## PHASE E — Finalize

- [ ] **Revert rig-demo:** `git checkout tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`.
- [ ] `composer test:unit` again → expect 782 + the new unit tests from Phases A/B (NOT the demo).
- [ ] **Cleanup operator-requested garbage:** `rm -rf .mockups/`; remove any stray screenshot/temp files; ensure
`git status` shows only intended framework changes (React/SCSS/PHP + tests + the two spec/plan docs).
- [ ] Rebuild bundle `npm run build:setup`; confirm LF EOL on `woodev/assets/build/**` (`.gitattributes` pins it).
- [ ] Commit docs (spec + this plan). Push branch.
- [ ] PR → **verify EACH CI job = pass + state CLEAN** (main has no required check) → `gh pr merge <N> --squash --delete-branch` (never `--auto`).

---

## Self-review (coverage vs spec)

- §2 tokens → Task C5 (+A/B carry min/max/step/tooltip). ✓
- §3 layout / bigger brand logo → C4 + C5. ✓
- §4 stepper + mandatory finish → B2 (terminal step) + C2. ✓
- §5.1 step description → B2 + C3. ✓
- §5.2 tooltip + description → A1/B1 (data) + C1/C6 (render). ✓
- §5.3 all control types → A1/A2 (consts/metadata) + B1 (schema) + C1/C1b. ✓
- §5.4 grouped fields → C3 + C5. ✓
- §6 skip-step vs exit footer → C4. ✓
- §7 finish (neutral copy, next-step cards, icon "also") → B3 + C4 + C6. ✓
- §11 demo + revert → D1/E. ✓ · §13 hygiene/cleanup → D3/E. ✓
- Deferred (§12): full richtext editor (MVP textarea+toolbar in C1), responsive polish, save-error styling.

**Open risk to confirm during D2:** `RangeControl`/`FormTokenField`/custom dropdown styling inside the
standalone full-screen page (only `style-index.css` + wp-components are enqueued) — verify they pick up the
scoped overrides; add styles if any wp-component renders unstyled.
