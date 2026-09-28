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
| deferred transactional e-mails / sending after `finally` | Dead **with the feature off** (the rig's default): the whole send happens inside `WC_Order->save()` under `apply_status` (A). **Superseded in Round 2** — with `woocommerce_defer_transactional_emails` on it is alive, see below. |
| the filter receives a different object or none | Dead — it receives `Order#11`, the edited order, and returns `false` (A: `woocommerce_email_disabled`). |
| a resend guard / `_new_order_email_sent` meta | Not involved — meta is `false` before and after the edit (A). |
| `trigger()` invoked from a hook our code does not wrap | Dead — the only `_notification` on the edit is the one under `apply_status` (A). |
| 8.5.1 / 9.3.0: real behaviour difference | Dead — `trigger()`, `is_enabled()`, `status_transition()` are functionally identical across the three; the difference is **when the mailer is first built** in the test process (B, C). |

## Limits

- The trace ran in the tests environment with `MockPHPMailer` (`wp_mail` returns `true`); delivery was not
  measured, only WooCommerce's decision to send.
- The framework's REST path (`WC()->mailer()` in `apply_status`, needed because a REST request does not build
  the mailer by itself) was exercised only through the service, not through an HTTP request.

## Round 2: deferred emails (the critic's blocker)

> Same rig, same three WooCommerce copies (11.1.0 in place; 9.3.0 and 8.5.1 piped in as
> `woocommerce-probe-{version}/` and selected by a temporary env override in `tests/bootstrap.php`,
> all removed afterwards). Fable 5.1 worker, 28.09.2026.

**The blocker was real.** Round 1's mute was a `woocommerce_email_enabled_new_order` filter added for
the duration of `$order->save()` and removed in `finally`. When a store or extension answers
`woocommerce_defer_transactional_emails` with `true`, the status transition only QUEUES the
`…_notification`; WooCommerce dispatches it in a later request, after the filter is gone, and the
pending→processing edit sends «New order». The round-1 test never drained the queue, so it stayed green.

### What each WooCommerce version does when it defers

| | 8.5.1 | 9.3.0 | 11.1.0 |
|---|---|---|---|
| decision | once, on `init`, in `WC_Emails::init_transactional_emails()`: filter `woocommerce_defer_transactional_emails` (default `false`) | same | same, default = feature flag `deferred_transactional_emails` (off) |
| queued by | `WC_Emails::queue_transactional_email` on every e-mail action → `WC_Background_Emailer::push_to_queue()` (in-memory list) | same | same → `DeferredEmailQueue::push()` (in-memory list; the order is replaced by `{type: order, id}`) |
| dispatched | `shutdown` 100: `save()` to an option (the order serialises to its id, `WC_Data::__sleep`), then a non-blocking loopback request runs `handle()` → `task()` | same | `shutdown` 100: one Action Scheduler action `woocommerce_send_queued_transactional_email` per notification, group `woocommerce-emails`; the order is re-read with `wc_get_order()` |
| the later request | `WC_Emails::send_queued_transactional_email( $filter, $args )` → filter `woocommerce_allow_send_queued_transactional_email` → `do_action_ref_array( $filter . '_notification', $args )` | same | same |
| `WC_Email_New_Order::trigger()` | resend guard `_new_order_email_sent` / `woocommerce_new_order_email_allows_resend`, then `is_enabled() && get_recipient()` → `send()` | same | resend guard, then `send_notification()` → `is_enabled()` |

So in every version the later request calls `is_enabled()` → `apply_filters( 'woocommerce_email_enabled_new_order', …, $this->object )` with an order object **re-read from the datastore**, under the `…_notification` action of the original transition. That is the seam: whatever the order itself carries is still there, and the action on the stack says which notification is being dispatched.

### The chosen mute

`Order_Editor::apply_status()` (an edit only) writes order meta **`_woodev_new_order_email_mute`** = the transition action (`woocommerce_order_status_{from}_to_{to}`) in the same `save()` that runs the transition. `Order_Editor::mute_new_order_email()` — hooked on `woocommerce_email_enabled_new_order` at `PHP_INT_MAX` by `Orders_Registry::add_hooks()`, so it is present in every request — answers `false` when the order carries the marker **and** `doing_action( $marker . '_notification' )`, and consumes the marker (`delete_meta_data()` + `save()`) as it does. After a synchronous transition, `apply_status()` drops a marker nothing consumed (New order not listening) unless WooCommerce's queue is hooked on that transition action (`has_action( $transition, [ 'WC_Emails', 'queue_transactional_email' ] )`), in which case the marker waits for the later request.

**An invented meta key.** WooCommerce's own key, `_new_order_email_sent`, was evaluated and rejected: setting it on an edit tells WooCommerce «this order was announced», which would also suppress a LATER legitimate «New order» (an edit pending→on-hold, then the customer pays and the gateway moves on-hold→processing — WooCommerce would have announced that, the guard would now block it), and it cannot be unset once the dispatch is deferred. `woocommerce_allow_send_queued_transactional_email` was rejected because it kills the whole notification — the customer's e-mail with it. `_woodev_new_order_email_mute` is therefore a new installed-site data contract; keep it byte-for-byte.

**Why it cannot hit a later, unrelated trigger.** The marker names ONE transition and is deleted the first time the New order e-mail is evaluated under that transition's notification. A different transition (`on-hold_to_processing`, `failed_to_processing`…) does not match. A manual «Resend new order notification» runs under no `_notification` action. Other orders and other e-mails never read it (`is_enabled()` of `new_order` only, and only with that order as `$object`; the settings screen passes no order). The single residual: if WooCommerce queued the edit's notification and the queue never ran (lost option, Action Scheduler never executes the action), the marker survives until the order makes the very same from→to transition again — one e-mail muted, once. Accepted in round 2 — **superseded in Round 3**, together with the single-slot marker itself (see below).

### Evidence

`OrderEditorDatastoresTest::test_an_update_sends_no_new_order_email_when_woocommerce_defers_its_emails` (both datastores): switches WooCommerce to deferral the way WooCommerce does it (unhook `send_transactional_email` from every e-mail action, filter → `true`, `WC_Emails::init_transactional_emails()`), creates a pending order, edits it to processing, asserts nothing left the request, then **drains the queue WooCommerce's way** — 11.1: `DeferredEmailQueue::dispatch()` + Action Scheduler's `process_action()` per pending action; 8.5.1/9.3.0: `WC_Background_Emailer::save()` + `handle()` — and asserts `customer_processing_order` = 1 (the drain delivered) and `new_order` = 0, the marker gone from a fresh read, then a create + drain still announces itself once.

| WooCommerce | fix stashed, test kept | with the fix (`--filter OrderEditorDatastoresTest`) | full Integration suite |
|---|---|---|---|
| 11.1.0 | 2 failures, `1 is identical to 0` (both datastores) | OK, 46 tests, 514 assertions | OK, 303 tests, 3761 assertions |
| 9.3.0 | 2 failures, `1 is identical to 0` | OK, 46 tests, 514 assertions | 303 tests, 3756 assertions, Skipped: 2 |
| 8.5.1 | 2 failures, `1 is identical to 0` | OK, 46 tests, 514 assertions | 303 tests, 3756 assertions, Skipped: 2 |

The red run on 9.3.0 / 8.5.1 doubles as proof that the legacy drain is real: the loopback's `handle()` re-read the order from its id and sent the e-mail the round-1 mute could not stop. `composer check` green (phpcs, phpstan, 4421 unit tests, incl. the new `OrderEditorEmailMuteTest`).

### The harness (finding 3)

`setUp()` → `ensure_mailer_listens()`: the repair (`WC_Emails::init()`) runs only when this mailer's own «New order» listener is missing; before it, every callback the stale e-mail objects still hold on any hook is removed (`unhook_object()`); after it — and always — `WC_Email_New_Order::trigger` and `WC_Email_Customer_Processing_Order::trigger` are asserted to sit on `…_pending_to_processing_notification` exactly once (`count_listeners()`). A partially restored hook table can neither silence nor double-send them unnoticed.

## Round 3: independent markers + TTL (the critic's blocker and major on round 2)

> Same rig, same three WooCommerce copies (11.1.0 in place; 8.5.1 and 9.3.0 copied into the tests
> container as `woocommerce-probe-{version}/` and selected by a temporary `WOODEV_WC_PROBE` override in
> `tests/bootstrap.php`, both removed afterwards). Fable 5.1 worker, 28.09.2026. Baseline `bea182c`.

**Both findings were real.** Round 2 kept ONE marker per order. (1) Under deferral, edit A pending→processing
queues its notification; before the queue runs, edit B processing→pending (allowed by the validator, and a
transition WooCommerce sends no e-mail for) overwrote A's marker with its own and then dropped it as
unconsumed — A's queued `pending_to_processing_notification` found no marker and «New order» went out.
(2) A marker whose dispatch was lost muted the next legitimate repeat of that transition, however much later.

### The design now

`_woodev_new_order_email_mute` (same key — the installed-site data contract keeps its name; the VALUE shape
changed while the branch is unreleased) holds a **list of entries**, oldest first:
`[ [ 'transition' => 'woocommerce_order_status_{from}_to_{to}', 'created_at' => <unix time> ], … ]`.

- `Order_Editor::apply_status()` (an edit only) **appends** one entry before the transition runs and never
  touches the others. After a synchronous transition it compares the number of entries matching this
  transition with the number before the edit: equal → the mute consumed this edit's entry; one more and
  WooCommerce's queue is NOT hooked on the transition (`has_action( $transition, [ 'WC_Emails',
  'queue_transactional_email' ] )`) → nothing will ever consume it, the newest matching entry is removed.
  Otherwise it waits for the deferred dispatch.
- `Order_Editor::mute_new_order_email()` **consumes the OLDEST entry** whose `transition . '_notification'`
  is `doing_action()` — the queue dispatches in order — answers `false` for that one evaluation, and writes the
  list back; **expired entries are pruned on every call that finds the order marked**, and the key is deleted
  once the list is empty. A value of another shape (round 2's single string, a foreign write) is treated as no
  entry and dropped.
- **TTL = `DAY_IN_SECONDS`** (`Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL`). Justification from how WooCommerce
  schedules the send: 10.8+ schedules one Action Scheduler action per notification for `time()`
  (`DeferredEmailQueue::dispatch()` → `WC_Action_Queue::add()` → `as_schedule_single_action( time(), … )`),
  run by the next queue runner (WP-Cron every minute, or the async runner on shutdown); ≤ 10.7 fires a
  non-blocking loopback on `shutdown` and a WP-Cron health check every 5 minutes (`WC_Background_Emailer`,
  `wp-background-process`). Both finish within seconds and retry within minutes. The one bound WooCommerce's
  own stack puts on «this should have run by now» is Action Scheduler's past-due threshold —
  `action_scheduler_pastdue_actions_seconds` = `DAY_IN_SECONDS` in the Action Scheduler bundled with 11.1
  (`ActionScheduler_AdminView`). A slow-but-working queue (a backlog, a store whose cron fires only on page
  views) therefore still meets its mute; a LOST queue stops muting after a day. A shorter TTL would trade the
  lost-queue residual for a real «New order» on an edit — the thing C4 forbids.
- **Write races** are considered only as far as one admin save path goes: two requests editing the same order
  at the same instant may each read the list before the other writes it, last `save()` wins. The wizard's
  contract (D5) serialises edits of one order; stated in the method's docblock.

### A third finding, from the 8.5.1/9.3.0 run

With the list in place the two-queued-edits test still left ONE entry behind on 8.5.1 and 9.3.0 (both
datastores), while 11.1 was clean. `WC_Background_Emailer::handle()` → `get_batch()` **unserialises the whole
batch at once**, so both queued notifications of the same order are constructed (`WC_Data::__wakeup()` →
re-read) BEFORE either runs: the second arrives with the meta the first has already consumed and saved, consumes
«one» from its stale copy and writes the stale remainder back. Action Scheduler (10.8+) runs one action per
request slot and `wc_get_order()`s the order at run time, hence no symptom there. Fix: a marked order's meta is
**re-read from the datastore** (`$order->read_meta_data( true )`) before anything is consumed; an order whose
key is gone by then passes through. Safe in the synchronous path too: `WC_Order::save()` persists meta before
`status_transition()` fires the notification, so the re-read returns exactly what the edit just wrote. Only
orders that carry the key pay the extra read.

### Evidence

**Red first, against `bea182c`** (production file stashed to the baseline, the six new tests run against it):

| new test (`OrderEditorDatastoresTest`, both datastores, deferral ON) | on `bea182c` |
|---|---|
| `test_an_edit_between_a_queued_edit_and_its_dispatch_does_not_unmute_it` | armed entries after edit B: `[]` instead of `[pending_to_processing]`; with the shape assertions removed (a throw-away probe copy): «edit A's «New order» stays muted although edit B ran in between — `1 is identical to 0`» |
| `test_two_queued_edits_of_the_same_transition_are_both_muted` | meta is a string, not a list; probe copy: «neither edit's «New order» goes out — `1 is identical to 0`» |
| `test_a_stale_entry_from_a_lost_queue_does_not_mute_a_later_transition` | meta is a string; probe copy: «a day-old entry of a lost queue mutes nothing — `0 is identical to 1`» |

6 failures out of 6 in both forms. The lost queue is simulated the way a request that dies before `shutdown`
loses it (the in-memory list emptied through the same private member the drain reads); the day is simulated by
moving the entries' `created_at` back past the TTL through the datastore, since the clock cannot be moved.

**Green with the fix:**

| WooCommerce | `--filter OrderEditorDatastoresTest` | full Integration suite |
|---|---|---|
| 11.1.0 | OK, 52 tests, 668 assertions | OK, 309 tests, 3915 assertions |
| 9.3.0 | OK, 52 tests, 670 assertions | 309 tests, 3912 assertions, Skipped: 2 |
| 8.5.1 | OK, 52 tests, 670 assertions | 309 tests, 3912 assertions, Skipped: 2 |

`composer check` green: phpcs, phpstan (237 files, no errors), 4426 unit tests — `OrderEditorEmailMuteTest`
now pins the list semantics: oldest matching entry consumed, a different transition untouched, an expired entry
pruned instead of muting, an expired one pruned alongside the consumed one, a stale object deferring to the
datastore, round 2's string shape dropped.

## Round 4: per-order lock (the critic's blocker on round 3, operator-licensed)

> Same rig; WooCommerce 11.1.0 in place, 8.5.1 and 9.3.0 downloaded from wordpress.org, copied into the
> tests container as `woocommerce-probe-{version}/` and selected by a temporary `WOODEV_WC_PROBE` override in
> `tests/bootstrap.php` — copies and override removed afterwards. Fable 5.1 worker, 28.09.2026. Baseline `34dbf97`.

**The blocker was real.** Round 3 left two admin saves of ONE order free to run at once (`apply_status()` said
so: last `save()` wins). A double submit or two tabs, both pending→processing, with deferred e-mails on: each
read no mute entry, each armed its own and ran the transition — two notifications queued — and the later meta
write dropped the first save's entry, so the drain found one entry for two notifications and the second sent
«New order».

### The design

`Order_Editor::update()` now runs **entirely under a MySQL named lock** — `SELECT GET_LOCK(name, timeout)`
on the request's own `$wpdb` connection, taken as the FIRST thing the method does and released with
`RELEASE_LOCK` in `finally`, whatever the answer (`lock_order()` / `unlock_order()`; the old body is
`update_locked()`). A lock not granted — timeout, or `NULL` from the server — answers **409
`woodev_shipping_order_busy`** with an admin-facing Russian message (new msgid, in `.pot` and `.po`; untranslated
entries do not enter the `.mo`), same `WP_Error` shape as the existing 409s, before anything is read or written.

- **Name:** `woodev_order_edit_{id}_{16 hex of md5(dbname|prefix)}` (`update_lock_name()`) — unique per order
  AND per site sharing one MySQL server (two databases, two prefixes in one database, a multisite's blog
  prefixes); at most 55 characters for any id, under MySQL's 64.
- **Where the read happens relative to the lock:** AFTER it, necessarily — nothing in `update()` touches the
  order before `lock_order()` returns, and the REST controller reads nothing before calling the service
  (`permissions_check()` is `current_user_can()` only). So the second save's `find_editable_row()` — both of
  them: the 404/409 gate and the pre-write re-read — is a first read in its request, made once the first save
  has committed. There is no earlier read whose runtime-cached copy (`get_post()` on CPT, `OrderCache` on HPOS)
  could go stale under the lock; that is why the lock sits at the top of `update()` and not after validation.
- **Timeout = 10 s** (`UPDATE_LOCK_TIMEOUT`, constructor-injectable for tests). The holder is one admin save: a
  handful of datastore writes, the carrier's writer and WooCommerce's transition — which, with synchronous
  e-mail, is up to two SMTP deliveries, usually well under a second each and seldom more than a few. Ten seconds
  covers the slowest realistic holder with margin, stays well under PHP's default `max_execution_time` (30 s)
  and the usual proxy timeouts (60 s) — so the waiter answers a clean 409 the wizard can show instead of dying
  as a gateway error — and a manager who double-clicked has the first click's answer long before. A holder
  that crashed is released by MySQL when its connection closes; no wait outlives its holder.
- **CREATE takes no lock.** A new order has no id until `wc_create_order()` returns and no concurrent editor
  before that; a double-submitted create is two orders, an idempotency question outside this card.
- The mute design is untouched. The residual write race is the deferred dispatch's own consumption in another
  request, which re-reads the meta before writing (round 3) and never runs inside an admin save.

### Evidence

**Red first, against `34dbf97`** (production file swapped to the baseline, the new tests kept):

| new test (`OrderEditorDatastoresTest`, both datastores) | on `34dbf97` |
|---|---|
| `test_a_save_of_an_order_another_request_is_saving_is_refused_with_409_and_changes_nothing` | `update_lock_name()` undefined (error); with the name inlined in a throw-away probe copy: the save went straight through the lock a second connection held — «`Order` object is not an instance of `WP_Error`» |
| `test_a_save_waits_for_the_save_in_flight_and_proceeds_once_it_is_released` | same error; probe copy: «the save waited for the release — `0.039` is not ≥ `0.9`» |
| `test_a_save_that_waited_acts_on_the_result_of_the_save_it_waited_for` | «the first save ran, and went through — `null` is not a `WC_Order`»: the lock seam the concurrent save runs through does not exist |
| unit `OrderEditorUpdateLockTest` (5) | 4 errors (no `update_lock_name()` / `UPDATE_LOCK_TIMEOUT`), 1 failure (a timed-out lock answered `unknown_order`, not `busy`) |

The «other request» in the integration tests is a real second `mysqli` connection to the test database
(`DB_HOST`/`DB_USER`/`DB_PASSWORD`/`DB_NAME`): it holds the order's lock with `GET_LOCK(name, 0)`, sees none of
the test's uncommitted rows and writes none. Test 1: an editor with a 1-second timeout answers 409 after waiting
its whole second, the order stays `pending`, no transition, nothing armed; after `RELEASE_LOCK` the same save
goes through; then `GET_LOCK(name, 0)` from the other connection succeeds at once — the editor's `finally` let
go. Test 2: the other connection runs `SELECT IF(SLEEP(1) = 0, RELEASE_LOCK(name), NULL)` **asynchronously**
(`MYSQLI_ASYNC`, mysqlnd), the default-timeout editor blocks ≈1 s and proceeds well inside its 10 s;
`reap_async_query()` confirms the release is what let it through. Test 3 — the critic's race end to end, under
deferral: an anonymous subclass overrides the protected `lock_order()` seam to run a complete first save
(lock taken and released) right before the waiting save's lock is granted; the waiting save then reads
`processing`, runs no transition and arms nothing (1 transition, 1 entry); the drain runs ONE notification,
delivers the customer's e-mail and sends **0 «New order»**; nothing is left armed. A true two-process race is
not buildable inside `WP_UnitTestCase` (its transaction hides the order from any other connection, so a second
process could not even load it); the seam is the honest substitute, and the argument for the read position is
the code order stated above.

**Green with the fix:**

| WooCommerce | `--filter OrderEditorDatastoresTest` | full Integration suite |
|---|---|---|
| 11.1.0 | OK, 58 tests, 810 assertions | OK, 315 tests, 4057 assertions |
| 9.3.0 | OK, 58 tests, 812 assertions | — (not required this round) |
| 8.5.1 | OK, 58 tests, 812 assertions (2nd and 3rd run; the 1st attempt, made seconds after `docker cp`, aborted with a PHP fatal during bootstrap whose head my output filter cut — not reproduced in two identical reruns) | — |

`composer check` green: phpcs, phpstan, 4431 unit tests (`OrderEditorUpdateLockTest` pins the name's uniqueness
and 64-char bound, the constructor's timeout in the `GET_LOCK` statement, the 409 for `'0'` and for `NULL` with
no `wc_get_order()` call, and the `RELEASE_LOCK` after a granted lock's 404). `OrderEditorGateTest` now installs
a `$wpdb` stand-in that grants the lock, since `update()` takes it before the gate it tests.
`npm run lint:i18n-sources`, `lint:i18n`, `lint:mo` green.

## Related

- [../../specs/2026-09-27-710-create-edit-order-design.md](../../specs/2026-09-27-710-create-edit-order-design.md) — C4, the rule under test
- [../2026-09-28-710-i0-measurement/README.md](../2026-09-28-710-i0-measurement/README.md) — I0 contradiction 1, why the editor issues no explicit trigger
- [../../gotcha-index/testing.md](../../gotcha-index/testing.md) — the WP_UnitTestCase hook-restore trap belongs here once compiled
- #981 — this card; #968 / PR #978 — the parent; #710 — the wizard
