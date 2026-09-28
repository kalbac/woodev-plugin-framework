# Gotcha: [woocommerce/email] — `woocommerce_email_recipient_{id}` is not a «sent» signal; count `woocommerce_email_sent`

> Tags: woocommerce, email, testing | Session: s143 (#981)

## What happens

A test that counts «New order» e-mails by hooking `woocommerce_email_recipient_new_order` reports 1 on
WC 11.x for an e-mail that was correctly muted and never sent — a false red that cost #968 three rounds.

## Root cause

Since WooCommerce **10.9**, a disabled e-mail still reads its recipient: `send_notification()` fires
`woocommerce_email_disabled` → `EmailLogger::handle_woocommerce_email_disabled()` →
`log_non_send_outcome()` → `$email->get_recipient()`. On 8.5.1 the same filter fires TWICE per real send
(`is_enabled() && get_recipient()`, then `send( $this->get_recipient(), … )`). The filter never meant «sent».

## Fix

❌ `add_filter( 'woocommerce_email_recipient_new_order', $count )`
✅ Count `woocommerce_email_sent` (fired after `wp_mail()`), filtered to the e-mail id you care about.
It proves a synchronous send only — a DEFERRED e-mail is sent in a later request, so drain the queue first
(gotcha [a-call-scoped-filter-cannot-mute-a-deferred-woocommerce-email](a-call-scoped-filter-cannot-mute-a-deferred-woocommerce-email.md)).

## Related

- [README.md](../research/2026-09-28-981-new-order-email-trace/README.md) — the trace, section A
- [wp-unittestcase-hook-restore-unhooks-woocommerce-email-listeners-before-wc-11](wp-unittestcase-hook-restore-unhooks-woocommerce-email-listeners-before-wc-11.md)
