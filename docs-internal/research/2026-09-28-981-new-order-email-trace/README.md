# #981 — where WooCommerce sends «New order» on an admin EDIT, and why WC 8.5.1 / 9.3.0 saw none on CREATE

> Session 142 (28.09.2026), macOS laptop, Fable 5.1 worker for card #981 (decomposed from #968 / PR #978).
> **Diagnosis by measurement.** Tests environment of the rig: WP 7.1, PHP 8.1.34, WooCommerce **11.1.0**
> (`woocommerce.latest-stable`), plus WooCommerce **9.3.0** and **8.5.1** downloaded from wordpress.org and
> piped into the tests container as `wp-content/plugins/woocommerce-probe-{version}/`, selected through a
> temporary env override in `tests/bootstrap.php`. Instrumentation was one throwaway integration test
> (`ZzProbe981Test`, an `all`-hook logger with backtraces at the e-mail hooks) and one throwaway mu-plugin
> (a backtrace at `woocommerce_email`). Bootstrap override, probe test, mu-plugin and the two WooCommerce
> copies were all **removed afterwards**; the branch diff is the one test file. Log excerpts below carry no
> cart keys — only hook names, object ids and the test site's `admin@example.org`.

## Conclusion first

1. **The round-3 mute WORKS. No «New order» leaves on an edit — on WC 11.1, 9.3.0 and 8.5.1.** What the
   red test counted was `woocommerce_email_recipient_new_order`, and since WooCommerce **10.9** that filter
   is also read for the e-mail WooCommerce did **not** send: `send_notification()` fires
   `woocommerce_email_disabled` → `EmailLogger::handle_woocommerce_email_disabled()` →
   `log_non_send_outcome()` → `$email->get_recipient()`. On 8.5.1 the same filter fires **twice** per real
   send (`is_enabled() && get_recipient()` then `send( $this->get_recipient(), … )`). It was never a
   «sent» signal. `Order_Editor` is unchanged.
2. **WC 8.5.1 / 9.3.0 = 0 e-mails on create is a test-harness artefact, reproduced locally and fixed in
   `setUp()`.** WooCommerce's e-mail objects register their `…_notification` listeners in their
   constructors, i.e. when the mailer singleton is **first built**. `WP_UnitTestCase` restores the hook
   table after every test, so once the singleton was built inside some test's scope its listeners are gone
   for the rest of the process while `WC()->mailer()` stays a no-op. WC 11.1 is immune only because it
   builds the mailer **during bootstrap** (`init` → `WC_Install::check_version` → `woocommerce_updated` →
   `EmailImprovements::should_enable_email_improvements…` → `WC()->mailer()`), so the listeners are in the
   backup that every restore reinstates.

## Method

- `tests/integration/Shipping/ZzProbe981Test.php` (deleted): `add_action( 'all', … )` at priority 1
  logging every `woocommerce_order_status_*`, `woocommerce_email*`, `woocommerce_new_order_email*`,
  `wp_mail`, with the callback priorities registered on `woocommerce_email_enabled_new_order` and a
  backtrace at the `_notification`, `_enabled_`, `_recipient_` and `wp_mail` hooks; plus, before and after
  `parent::setUp()`, whether `WC_Emails::$instance` exists and whether
  `woocommerce_order_status_pending_to_processing_notification` has a listener. Two tests in one class, so
  the second one sees the state the first one leaves behind — the shape CI runs in.
- The same probe run against 9.3.0 and 8.5.1 through `WOODEV_PROBE_WC_DIR` (temporary bootstrap override).
- Red baseline: the `HEAD` (`6d6a24c`) version of `OrderEditorDatastoresTest` run on 11.1 and 9.3.0.
- Green: the fixed test on all three, then the full Integration suite on all three.

## Evidence

### A. WC 11.1 — the edit, with the round-3 mute in place (`_notification` → mute → EmailLogger)

```
=== UPDATE pending->processing order#11
woocommerce_order_status_processing(11, Order#11, array(from,to,note,manual))
woocommerce_order_status_pending_to_processing(11, Order#11)
woocommerce_order_status_pending_to_processing_notification(array(0,1))
   bt: WC_Emails::send_transactional_email < WC_Order->status_transition < WC_Order->save
       < Order_Editor->apply_status:328 < Order_Editor->write:179 < Order_Editor->update:85
woocommerce_email_enabled_new_order(true, Order#11, WC_Email_New_Order) | filter prios=10,9223372036854775807
   bt: WC_Email->is_enabled:1142 < WC_Email->send_notification:127 < WC_Email_New_Order->trigger:353
woocommerce_email_disabled('new_order', WC_Email_New_Order)                       <-- the mute took
woocommerce_email_log_enabled(true, 'new_order', WC_Email_New_Order)
woocommerce_email_recipient_new_order('admin@example.org', Order#11, WC_Email_New_Order)   <-- what the test counted
   bt: WC_Email->get_recipient:265 < EmailLogger->log_non_send_outcome:209
       < EmailLogger->handle_woocommerce_email_disabled:353 < do_action:1151 < WC_Email->send_notification:127
woocommerce_email_enabled_customer_processing_order(true, Order#11, …)             <-- customer mail, untouched (C4)
wp_mail(...)  bt: WC_Email->send:1179 < WC_Email->send_notification:102 < WC_Email_Customer_Processing_Order…
woocommerce_email_sent(true, 'customer_processing_order', …)
=== after update; new_order_email_sent meta=false
```

No `wp_mail` and no `woocommerce_email_sent` for `new_order` between «UPDATE» and «after update»; the
`_new_order_email_sent` meta stays `false`. The mute is the second priority (`PHP_INT_MAX`) on the filter and
it is present exactly for the duration of the transition — the later create in the same test shows
`filter prios=10` and a real send (`woocommerce_email_sent(true, 'new_order', …)`). Deferred transactional
e-mails were **off** (`deferred=false`, the 11.1 feature flag `deferred_transactional_emails` defaults to
off), so the «sent after `finally`» candidate is dead too.

### B. WC 9.3.0 — same probe, two tests in a row (the harness artefact)

```
PRE-setUp:          mailer built=false  has_notification=false   has_pending_to_processing=true
POST-parent::setUp: mailer built=false  has_notification=false
POST-mailer():                          has_notification=true    <-- built AFTER _backup_hooks()
=== CREATE processing …  woocommerce_email_sent(true, 'new_order', WC_Email_New_Order)   (1 e-mail, as expected)
--- next test in the same process ---
PRE-setUp:          mailer built=true   has_notification=false   <-- _restore_hooks() dropped the listener
POST-parent::setUp: mailer built=true   has_notification=false
POST-mailer():                          has_notification=false   <-- singleton: no-op
=== SECOND TEST CREATE processing
woocommerce_order_status_pending_to_processing_notification(array(0,1))          <-- fired, nobody listening
=== END2                                                                          (0 e-mails)
```

WC 8.5.1 gives the identical picture. `woocommerce_order_status_pending_to_processing` itself keeps its
listener (`WC_Emails::send_transactional_email`, registered on `init` during bootstrap) — it is the e-mail
objects' `_notification` listeners that vanish. Round 3's `WC()->mailer()` in `setUp()` could only ever help
the FIRST test to build the mailer; in CI that test is somewhere earlier in the run.

### C. WC 11.1 — who builds the mailer before any test (mu-plugin backtrace at `woocommerce_email`)

```
did init=1  current=init>woocommerce_updated>woocommerce_email
WC_Emails::instance() < WooCommerce->mailer()
  < EmailImprovements::…(259) < … < EmailImprovements::should_enable_email_improvements…
  < WC_Install::enable_email_improvements_for_existing_merchants  (class-wc-install.php:1376)
  < do_action('woocommerce_updated')  (class-wc-install.php:458) < WC_Install::check_version
  < do_action('init')  (wp-settings.php:779) < /wordpress-phpunit/includes/bootstrap.php:301
```

That is why WC 11 never showed the create-side symptom: its listeners predate the first `_backup_hooks()`.

### D. Baseline and fix, side by side (`--filter OrderEditorDatastoresTest`, 44 tests)

| WooCommerce | `HEAD` test (`6d6a24c`) | fixed test |
|---|---|---|
| 11.1.0 | 2 failures — update test, `1 is identical to 0` at l.466 (both datastores) | OK, 384 assertions |
| 9.3.0 | 4 failures — create test l.439 and update test l.469, `0 is identical to 1` | OK, 384 assertions |
| 8.5.1 | (same as 9.3.0 in CI run 36380371888) | OK, 384 assertions |

The local baseline reproduces CI run 36380371888 line for line. Full Integration suite after the fix:
11.1.0 `OK (284 tests, 3496 assertions)`; 9.3.0 and 8.5.1 `284 tests, 3491 assertions, Skipped: 2` (the
two skips CI reports on those versions too). `composer check` green (phpcs, phpstan, 4364 unit tests).

## The fix (test file only)

1. **`setUp()`:** after `parent::setUp()`, `WC()->mailer()` and, when this mailer's own
   `WC_Email_New_Order::trigger` is not listening on `…_pending_to_processing_notification`,
   `$mailer->init()` — WooCommerce's public re-registration of its e-mail objects (11.1's own REST controller
   calls it). Scoped by construction: the hooks land after the backup and `WP_UnitTestCase` drops them again.
   On 11.1 the condition is false and nothing is re-registered, so nothing double-sends.
2. **Counting:** both e-mail tests count on `woocommerce_email_sent` filtered to `new_order` — fired once per
   `wp_mail()` of a transactional e-mail on every supported WooCommerce (8.5.1 `class-wc-email.php:721`,
   9.3.0 `:758`, 11.1 `:1268`) and never for a disabled or muted one. `woocommerce_email_recipient_new_order`
   and `woocommerce_email_enabled_new_order` are both wrong for the purpose (Conclusion 1).

## Candidates from the brief, closed

| Candidate | Verdict |
|---|---|
| deferred transactional e-mails / sending after `finally` | Dead — feature off, the whole send happens inside `WC_Order->save()` under `apply_status` (A). |
| the filter receives a different object or none | Dead — it receives `Order#11`, the edited order, and returns `false` (A: `woocommerce_email_disabled`). |
| a resend guard / `_new_order_email_sent` meta | Not involved — meta is `false` before and after the edit (A). |
| `trigger()` invoked from a hook our code does not wrap | Dead — the only `_notification` on the edit is the one under `apply_status` (A). |
| 8.5.1 / 9.3.0: real behaviour difference | Dead — `trigger()`, `is_enabled()`, `status_transition()` are functionally identical across the three; the difference is **when the mailer is first built** in the test process (B, C). |

## Limits

- The trace ran in the tests environment with `MockPHPMailer` (`wp_mail` returns `true`); delivery was not
  measured, only WooCommerce's decision to send.
- The framework's REST path (`WC()->mailer()` in `apply_status`, needed because a REST request does not build
  the mailer by itself) was exercised only through the service, not through an HTTP request.

## Related

- [../../specs/2026-09-27-710-create-edit-order-design.md](../../specs/2026-09-27-710-create-edit-order-design.md) — C4, the rule under test
- [../2026-09-28-710-i0-measurement/README.md](../2026-09-28-710-i0-measurement/README.md) — I0 contradiction 1, why the editor issues no explicit trigger
- [../../gotcha-index/testing.md](../../gotcha-index/testing.md) — the WP_UnitTestCase hook-restore trap belongs here once compiled
- #981 — this card; #968 / PR #978 — the parent; #710 — the wizard
