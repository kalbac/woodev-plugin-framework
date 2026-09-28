# Gotcha: [testing/integration] — WP_UnitTestCase's hook restore silently unhooks WooCommerce's e-mail listeners on WC < 11, so an integration test sees 0 e-mails

> Tags: testing, integration, woocommerce, email | Session: s143 (#981)

## What happens

An integration test that expects WooCommerce's «New order» on a status transition passes on WC 11.x and
counts **0** e-mails on WC 8.5.1 / 9.3.0 (CI matrix, both datastores). WooCommerce's source for the
transition is the same in all three, so it reads like a CI-only fluke — it is not; it reproduces locally.

## Root cause

A `WC_Email` object registers its `woocommerce_order_status_*_notification` listeners in its constructor,
i.e. when the mailer singleton `WC()->mailer()` is FIRST built. `WP_UnitTestCase` backs up the hook table
before each test and restores it after. If the singleton is first built INSIDE a test, its listeners are
dropped by that test's restore — and the singleton stays built, so every later `WC()->mailer()` is a no-op
and nothing re-registers them for the rest of the process.

WC 11 is immune only by accident: it builds the mailer during bootstrap (`init` → `WC_Install::check_version`
→ `woocommerce_updated` → `EmailImprovements` → `WC()->mailer()`), so the listeners are part of the backup.
Calling `WC()->mailer()` in `setUp()` does NOT help (tried in #968 round 3) — the singleton already exists.

## Fix

❌ `WC()->mailer();` in `setUp()` and hope.
✅ In `setUp()`, check whether this mailer's own `WC_Email_New_Order::trigger` is still hooked on the
notification action; if not, strip the stale e-mail objects' callbacks and call `WC_Emails::init()` again,
then assert each counted e-mail is hooked exactly once (so a partial hook state cannot double-register).
Reference: `tests/integration/Shipping/OrderEditorDatastoresTest.php` → `ensure_mailer_listens()`.

## Related

- [README.md](../research/2026-09-28-981-new-order-email-trace/README.md) — the trace, section B
- [woocommerce-email-recipient-filter-is-not-a-sent-signal](woocommerce-email-recipient-filter-is-not-a-sent-signal.md) — the other half of the same red test
