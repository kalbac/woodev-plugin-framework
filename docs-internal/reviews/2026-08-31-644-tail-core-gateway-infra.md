# Card #644 tail pass — core, gateway, licensing, and infrastructure

> Reviewed: 2026-08-31. Scope: open cards #103, #104, #105, #106, #107, #108,
> #109, #110, #111, #112, #113, #114, #115, #117, #118, #119, #120, #121,
> #122, #123, #124, #125, #126, #129, #130, #138, #140, #381, and #382.

Serena MCP was not available to this Codex worker. The review therefore used targeted
source searches/reads plus Git history and existing unit tests as the independent check.

| Card | Claim | Checked how (file:line / commit) | Verdict | Commented? |
| --- | --- | --- | --- | --- |
| #103 | Conditional-fields v2 capabilities are deferred pending a shipping pilot. | `woodev/settings-api/class-setting.php:801-845` only implements `=`, `!=`, `in`, and `not_in`; `src/components/validate.js:258-321` mirrors that limited set. | Accurate; no in-repository shipping pilot demonstrates the requested need. | No |
| #104 | A v1 bootstrap winner silently prevents v2 loading. | `woodev/bootstrap.php:90-132` only quarantines a v1 registration when the v2 bootstrap won; no inverse rendezvous hook exists. | Accurate; the described inverse winner has no v2 code path to render a notice. | No |
| #105 | Licences-page React layout needs a visual pass. | `woodev/admin/class-admin-pages.php:112-199` wires the page, but source inspection cannot establish a visual defect. | Unverified: requires the browser rig. | No |
| #106 | Updater needs F1 sections normalization and F3 store-cache isolation. | `woodev/licensing/updater/class-plugin-updater.php:615-623` normalizes fresh `sections`; commit `c66a955 fix(updater): OB-3 F1/F3` introduced the repair. | Already fixed. | Yes |
| #107 | Reusable framework JS needs a PHP-driven design convention. | No executable defect is asserted; this is a documentation/design decision. | Unverified by code; no entry condition can be established from this repository alone. | No |
| #108 | Research GoDaddy framework patterns for v2. | Research task, not a present code defect. | Unverified by code. | No |
| #109 | Setup Wizard needs a v2 state audit. | `woodev/setup/class-setup-wizard.php:20-91` and `woodev/class-plugin.php:317-339` still expose an opt-in base implementation. | Accurate: the subsystem remains present and is suitable for the proposed audit. | No |
| #110 | Wizard has forward-navigation, false-success, and secret-schema gaps. | `src/setup-wizard/app.js:148-194` maps server errors; `woodev/rest-api/controllers/class-rest-api-setup.php:127-151,205-209` supplies them and prevents false success; `src/setup-wizard/stepper.js:29-53` still permits arbitrary non-current navigation. Commit `78ead18` fixes the error-map part. | Scope reduced: two cited defects are fixed; forward-navigation remains. | Yes |
| #111 | Dead-file sweep is needed. | `woodev/class-map.php:1-230` supplies runtime mappings, while several legacy `includes()` lists remain. | Accurate as an audit task; no single deletion is safely inferable without consumer analysis. | No |
| #112 | Plugins page requires modernization and account integration. | `woodev/admin/class-admin-pages.php:285-318` loads the React plugins page and `src/plugins-page/account.js:35-125` provides account connect/disconnect UI. | Scope changed: React page and account integration already exist; visual/product gap needs browser/product review. | Yes |
| #113 | Add a Woodev marketplace tab on plugin-install.php. | `woodev/admin/class-plugin-install-tab.php:8-65` registers and redirects the tab; commits `622fea0` and `8f19dcd` implement it. | Already fixed. | Yes |
| #114 | Collect shipping-module operator nuances. | No code claim; the card is a discussion placeholder. | Unverified by code. | No |
| #115 | Trigger-gated Fable architecture items remain. | `woodev/class-framework-plugin-loader-definition.php:306` still rejects EDD; `woodev/bootstrap.php:90-132` has the v1 tombstone. | Partly code-backed, but each remaining item has a distinct external trigger; no trigger was demonstrated here. | No |
| #117 | Extract payment-gateway traits from a large class. | `woodev/payment-gateway/class-payment-gateway.php` is 3,629 lines; `rg 'trait '` finds no extracted traits in that module. | Accurate; scope has grown slightly from the stated ~3.5k lines. | No |
| #118 | Remove deprecated eCheck wrappers after production migration. | `woodev/payment-gateway/class-payment-gateway.php:124-130` and `payment-tokens/class-payment-gateway-payment-token.php:116-121` retain the deprecated false-return wrappers. | Accurate; production-use entry condition is not verifiable in this repository. | No |
| #119 | Add server-to-client notifications/webhooks after v2. | `woodev/licensing/api/class-rest-api-license-command.php:41-97` is an inbound signed command route; no generic outbound client notification module was found. | Accurate; the existing inbound route is not the requested transport. | No |
| #120 | Provide a complete shipping-plugin scaffold. | `woodev/shipping-method/class-shipping-plugin.php:123-271` now wires checkout, location, pickup, order, webhook, and admin seams. | Scope shrunk: substantial scaffold exists, but carrier API/rates/labels/tracking implementations remain host-owned. | Yes |
| #121 | Gradually move admin UI toward React. | React surfaces are loaded by `woodev/admin/class-admin-pages.php:112-199,285-318` and settings-page code lives under `src/settings-page/`. | Scope changed: staged React admin UI and REST foundations already ship; broad migration remains. | Yes |
| #122 | Split platform base from WooCommerce and later add EDD. | `woodev/class-woocommerce-plugin.php:18-80` exists; `woodev/class-plugin.php:80,1121` identifies pure-WP use; `class-framework-plugin-loader-definition.php:306` reserves EDD as unsupported. Commit `1aa4ec4` introduced the platform split. | Partly fixed: Base/Woo split exists; EDD remains deferred. | Yes |
| #123 | Do not auto-start ecosystem orchestration until v2 is stable. | `woodev/class-plugin.php:17` still declares framework version `2.0.1`; no ecosystem orchestrator source exists. | Entry condition not demonstrated; no implementation should be inferred. | No |
| #124 | Clear local account state after server revocation. | `woodev/account/class-account-connection.php:395-410` has explicit disconnect, but failed signed requests return errors without a 401/invalid-token cleanup branch. | Accurate; full-v2/migration gate is external and unverified. | No |
| #125 | Create a free framework hub after migration. | `woodev/admin/class-admin-pages.php:285-318` requires an already active framework plugin to render the catalog; no standalone hub plugin exists. | Accurate; migration gate is external and unverified. | No |
| #126 | Support custom field/section renderers against a real carrier consumer. | `src/components/control-field.js` contains fixed control switching; no `controlRenderer`/section-component provider hook occurs in `src/` or Settings API source. | Accurate; no real carrier consumer is in this repository. | No |
| #129 | Add block-level secret disconnect. | `src/components/control-field.js:115-160` implements per-field clearing; no connection-block bulk-clear control was found. | Accurate. | No |
| #130 | Add an opt-in lightweight client-plugin error reporter. | No reporter module or global error handler exists under `woodev/`; REST routes found are application-specific. | Accurate; post-migration gate is external and unverified. | No |
| #138 | Decide whether Shipping_Plugin::includes() should remain alongside class-map. | `woodev/shipping-method/class-shipping-plugin.php:123-233` still has manual requirements; `woodev/class-map.php:1-230` remains an overlapping autoload map. | Accurate; dual wiring still exists. | No |
| #140 | Competing global REST request stubs prevent request type hints. | `tests/unit/LicenseCommandRestTest.php:93-106` still conditionally defines a global stub; but shipping REST stubs are namespace-scoped at `tests/unit/Shipping/Rest_Api/PickupControllerTest.php:43-55`; production callbacks remain untyped at `class-pickup-controller.php:486,531,773,816`. | Scope changed: the specific shipping-global collision was removed, while the requested type restoration remains undone. | Yes |
| #381 | Assess Orca capabilities for the workflow. | No framework source claim; Orca is external tooling. | Unverified by repository code. | No |
| #382 | Triage findings from a delayed local-model review. | Card comments contain the review and later verification, but the card itself contains no independently specified live source finding to re-check. | Scope changed procedurally; code-verification is not applicable without individual finding cards. | No |

## Candidates for closure

- #106 — F1/F3 were implemented by `c66a955` and are present in current updater code.
- #113 — the plugin-install marketplace tab and React-catalog redirect are present.

## Entry conditions that have now fired

None demonstrated from framework source alone. The outstanding gates for #103, #118, #123,
#124, #125, and #130 depend on real plugins, production migration, or an operator decision.

## Related

- [Card #644](https://github.com/kalbac/woodev-plugin-framework/issues/644) — audit umbrella and required verification standard.
- [Platform v2 architecture](../wiki/architecture.md) — relevant context for #104, #115, and #122.
