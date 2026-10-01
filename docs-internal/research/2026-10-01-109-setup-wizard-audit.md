# Issue #109 — Setup Wizard Audit

**Date:** 2026-10-01  
**Scope:** Read-only audit of the framework wizard, REST controller, React UI, fixture declaration/tests, and author API. No code changed and no integration suite run.  
**Serena:** Unavailable in this Codex worker; PHP inspection used shell with line-numbered source.

## Executive summary

The v2 wizard is an opt-in, PHP-declared React admin onboarding flow with settings and trusted-content steps, a WooCommerce specialization, Settings API validation/persistence, and completion/skip state. The recent #110 work prevents client-side forward jumps, but the server still has no per-step progress or prerequisite enforcement. The API is adequate for simple settings collection; a real carrier setup flow that must test credentials, branch on carrier configuration, and prove readiness needs additional server-side step/action contracts.

## 1. Current behavior and data flow — facts

- The plugin base defaults the wizard factory to null; plugins opt in by overriding it ([class-plugin.php:428-455](../../woodev/class-plugin.php)). The fixture returns its handler at [woodev-test-plugin.php:383-390](../../tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php).
- Authors subclass Setup_Wizard or Woocommerce_Setup_Wizard, implement register_steps(), then call register_step(id, label, setting_ids, on_save?, description?) or register_content_step(id, label, content, description?) ([class-setup-wizard.php:47-63,102-130](../../woodev/setup/class-setup-wizard.php)). Step stores id, label, type, setting ids or content, optional on_save, description, and a server-evaluated visibility predicate ([class-step.php:17-47,64-116](../../woodev/setup/class-step.php)).
- Construction builds and filters visible steps, then wires hooks only if steps remain ([class-setup-wizard.php:47-53,72-100,140-152](../../woodev/setup/class-setup-wizard.php)). Hooks provide first-install redirect, hidden standalone admin page, notice/action link, and REST registration. Page access checks the configured capability; neutral default is manage_options ([class-setup-wizard.php:25-29,321-333,386-399,440-456](../../woodev/setup/class-setup-wizard.php)). The WC subclass requires manage_woocommerce and suppresses hooks when WC is inactive ([class-woocommerce-setup-wizard.php:21-45](../../woodev/setup/class-woocommerce-setup-wizard.php)).
- PHP resolves referenced setting ids to field schema/defaults, invokes content callbacks during bootstrap, appends a built-in finish step, and inlines plugin id, steps, REST root, REST nonce, global state, admin URL and finish links ([class-setup-wizard.php:547-607](../../woodev/setup/class-setup-wizard.php)). React renders that data. Settings values stay in React until Continue; the server persists each submitted setting as it validates it.
- The REST controller exposes per-step save and complete routes under woodev/v1; both use current_user_can() with the wizard capability ([class-rest-api-setup.php:50-73,75-84](../../woodev/rest-api/controllers/class-rest-api-setup.php)). The client sends the wp_rest nonce in X-WP-Nonce ([rest.js:30-38,47-55](../../src/setup-wizard/rest.js)). Save accepts registered step ids, filters currently hidden settings, updates declared ids, then calls optional on_save; unexpected storage errors receive a generic 500, while validation errors are keyed by field id ([class-rest-api-setup.php:100-188](../../woodev/rest-api/controllers/class-rest-api-setup.php)).
- Continue validates visible fields client-side, then saves settings steps and advances only on success; content steps advance without a request. Skip advances without saving. Footer exit best-effort marks the wizard skipped; entering Finish POSTs completed then displays success ([app.js:249-318,320-456](../../src/setup-wizard/app.js)). #110 item 2 now permits only previously visited steps, never Finish; the boundary is per-tab sessionStorage, not server state ([app.js:13-20,49-89,113-125,157-184,228-238](../../src/setup-wizard/app.js); [stepper.tsx:25-39,48-77](../../src/components/stepper.tsx); [navigation tests:110-237](../../tests/js/setup-wizard-navigation.test.js)).
- The fixture declares Welcome content and Contacts settings steps; its server-only phone validator demonstrates a constraint browser rules cannot mirror ([woodev-test-plugin.php:174-208,251-263](../../tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php)). Unit coverage includes registration, bootstrap, state, capability, REST save/complete/errors and WC specialization ([SetupWizardRestControllerTest.php:13-63](../../tests/unit/SetupWizardRestControllerTest.php); [SetupWizardStateTest.php](../../tests/unit/SetupWizardStateTest.php); [WoocommerceSetupWizardTest.php](../../tests/unit/WoocommerceSetupWizardTest.php)).

## 2. Defects and risks — evidence and assessment

### Correctness and state

- **Server workflow is not enforced.** REST complete accepts skipped or normalizes any other/missing value to completed and writes state without checking required steps or prior saves ([class-rest-api-setup.php:199-219](../../woodev/rest-api/controllers/class-rest-api-setup.php); [class-setup-wizard.php:277-281](../../woodev/setup/class-setup-wizard.php)). A crafted request by an authorized user can finish directly; #110's UI restriction does not establish server prerequisites. Impact depends on whether plugins interpret completion as readiness.
- **State can move both ways.** Repeated calls overwrite completed with skipped or vice versa. Same-state retries are benign, but calls are not monotonic/idempotent across differing values. The React Finish effect can POST again if remounted ([app.js:201-210](../../src/setup-wizard/app.js)).
- **Partial persistence is intentional.** Setting N may fail after settings 0..N-1 were saved; on_save also runs after persistence, so its failure leaves settings changed and requires idempotent callback behavior ([class-rest-api-setup.php:87-94,120-180](../../woodev/rest-api/controllers/class-rest-api-setup.php)). on_save catches Exception, not all Throwable, unlike adjacent persistence paths; a PHP Error can escape.
- **No mandatory-step API.** UI renders Skip for settings steps unless step.skippable is false, but PHP bootstrap never emits skippable ([app.js:414-424](../../src/setup-wizard/app.js); [class-setup-wizard.php:576-583](../../woodev/setup/class-setup-wizard.php)). Current steps are therefore all skippable. This blocks required carrier setup.
- **No draft rehydration/progress record.** React values initialize empty; values are posted on leaving a settings step, but no per-step draft/progress is returned on page load ([app.js:127-145,249-279](../../src/setup-wizard/app.js)). A reload can reopen a visited hash but cannot reliably resume unsaved step state.

### Security, capability and nonce

- **Positive controls:** the page checks capability at menu registration and full-screen rendering; REST callbacks use a capability callback; the client sends a REST nonce; writes are restricted to ids declared on the registered step, and on_save sees only declared values ([class-setup-wizard.php:386-399,440-451,595-603](../../woodev/setup/class-setup-wizard.php); [class-rest-api-setup.php:54-71,82-84,120-125,168-174](../../woodev/rest-api/controllers/class-rest-api-setup.php)). WordPress cookie-auth REST nonce validation is the expected CSRF layer; permissions_check itself checks capability only.
- **Navigation is client-side trust.** An authorized user can post save for any registered step or call complete directly, independent of prior steps. This is not an unauthenticated privilege escalation, but it means the API does not promise sequence/readiness.
- **Raw content boundary:** content callbacks/strings enter React via dangerouslySetInnerHTML ([step-view.js:122-133](../../src/setup-wizard/step-view.js)). Plugin-owned trusted output is reasonable, but dynamic values must be escaped; the API should document this clearly.
- Step ids are looked up in the registered map and unknown ids return 404 ([class-rest-api-setup.php:54-61,100-108](../../woodev/rest-api/controllers/class-rest-api-setup.php)); no concrete route injection issue found.

### I18n and accessibility

- This is admin UI. Russian msgids are allowed by the repository's admin render-path rule, and reviewed wrappers use woodev-plugin-framework. No storefront msgid issue was found. Plugin validation exception text is also shown in admin and should be localized ([app.js:269-290](../../src/setup-wizard/app.js)).
- **A11y gap:** stepper is an ordered list of buttons/spans, but active step lacks aria-current=step and the current/completed state is conveyed by CSS class only ([stepper.tsx:48-77](../../src/components/stepper.tsx)). Step title is h1, but focus does not move to it on step change. Error summary uses role=alert and invalid field focus is attempted ([app.js:212-226,365-370](../../src/setup-wizard/app.js)).
- **Failure is hidden at finish/exit:** complete failure only console.warns while success still renders; skip failure still redirects ([app.js:201-210,309-318](../../src/setup-wizard/app.js)). UI can therefore disagree with stored state. Save errors do surface an alert and field map ([app.js:283-291](../../src/setup-wizard/app.js)).

## 3. Real carrier-plugin authoring gaps — assessment

- **Conditional steps:** visibility predicate runs once when steps are built; it cannot react to unsaved choices in this wizard ([class-step.php:46-47,104-116,202-208](../../woodev/setup/class-step.php); [class-setup-wizard.php:72-99](../../woodev/setup/class-setup-wizard.php)). It can reflect existing server configuration, not branch on a mode chosen in the current flow.
- **Credential/API check:** on_save can run a side effect after persistence, but it is not a separate action, cannot return structured success data, and reports failure after credentials have already been saved ([class-rest-api-setup.php:168-180](../../woodev/rest-api/controllers/class-rest-api-setup.php)). No test API key action or pending/success/error state exists.
- **Validation/requiredness:** setting validators exist, but there is no author contract for content-step business validation, cross-step checks, async validation, non-skippable steps, or server-enforced sequence. Throwing from on_save is side-effect-after-persistence, not a clean validation lifecycle.
- **Progress/resume:** only global completed/skipped is persisted. Per-step visited/completed/errors and partial draft state are absent. Authors cannot show durable progress, resume across tabs/devices, or gate final completion on carrier readiness.
- **Presentation/control:** content is raw HTML or callback output; no typed action/button/notice/polling control exists without custom markup/script.

## 4. WooCommerce comparison — facts and limits

The v2 design records that the former framework wizard was a near-exact fork of the WooCommerce/SkyVerge setup wizard: server-rendered wc-setup markup, WooCommerce-coupled, manage_woocommerce, page reload per step, and unused by current fixtures ([archived design:10-18](../../docs-internal/archive/specs/2026-06-22-setup-wizard-design.md)). Current React UI borrows WooCommerce's branded onboarding layout and anchor hash pattern ([app.js:4-8,157-163](../../src/setup-wizard/app.js)); shared stepper comment says it reproduces WC's progress-line stepper ([stepper.tsx:2-12](../../src/components/stepper.tsx)). No vendored WooCommerce core tree was present, so this report makes no line-level claim about current WC core. plugins-reference is v1-era and cannot establish v2 API patterns ([gotcha:16-29,36-44](../gotchas/plugins-reference-predates-every-v2-api.md)).

## 5. Ranked proposals — opinion

| Rank | Proposal | Size | What it unblocks | Operator decision? |
|---:|---|:---:|---|---|
| 1 | Define completion contract: distinguish dismissed from ready; if completed means ready, require server-side plugin readiness/prerequisite validation and keep skip a separate terminal state. | M | Prevents false-ready state and enables carrier activation gating. | **Yes** — semantic and persisted-state contract; central #109 decision. |
| 2 | Add first-class step contract: skippable, server validation callback returning structured errors, and typed async action with structured result (test credentials without persisting). | L | Required credentials and reliable “test API key” flow. | **Yes** — choose framework primitives versus plugin-owned actions. |
| 3 | Persist progress/drafts per wizard with step ids/versioning; validate step order and completion against current visible step graph. | L | Durable resume, progress display, server workflow enforcement. | **Yes** — new data contract plus behavior when steps change. |
| 4 | Make conditions reactive to declared values, with server recalculation and visibility validation per request. | M | Mode-dependent carrier branches inside one wizard. | **Yes** — graph semantics when a choice hides an already-saved step. |
| 5 | Add aria-current=step, focus heading after navigation, textual step status, and visible error state when complete/skip persistence fails. | S | Screen-reader orientation and honest finish behavior. | No — contained accessibility/correctness fix. |
| 6 | Catch Throwable around plugin callbacks, redact/log unexpected details, expose only safe structured validation errors, document partial-save/idempotency behavior. | S | Predictable errors and fewer uncaught PHP failures. | No — contained reliability fix. |
| 7 | Document a minimal carrier example covering opt-in, capability, settings/content, validation, escaping and persistence. | S | Easier author discovery and fewer raw-HTML/security mistakes. | No — docs-only after API direction is settled. |

## Related

- [Issue #109](https://github.com/kalbac/woodev-plugin-framework/issues/109) — audit request and operator decision.
- [Issue #110](https://github.com/kalbac/woodev-plugin-framework/issues/110) — stepper navigation and #1044.
- [Archived wizard design](../archive/specs/2026-06-22-setup-wizard-design.md) — architecture decisions.
- [Plugins reference gotcha](../gotchas/plugins-reference-predates-every-v2-api.md) — limits of legacy examples.
- [DOCS-SCHEMA](../DOCS-SCHEMA.md) — internal documentation conventions.
