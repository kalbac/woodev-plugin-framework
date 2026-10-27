Provenance: D5 ticket history (research E, 26.10.2026) — a Codex worker read all FreeScout conversations read-only through the support assistant's FreeScout API; anonymised summary, no merchant, buyer or order identifiers.

# T-Bank «Купи в кредит / Рассрочки и кредиты»: cancellation history

## Read path and coverage

Read path: the repository-documented FreeScout REST API, using the already configured FreeScout credentials
through the local MCP configuration; conversations were paged in creation order with their threads embedded
(`embed=threads`). The MCP search tool itself only searches conversation subjects, so it was not used as a
body-search substitute. All 4,319 conversations available to the API were scanned, covering 2023–2026, and
candidate threads were reviewed. The Qdrant ticket collection is an older partial archive (835 distinct
conversation IDs), so it was not treated as complete history.

This is a count of distinct FreeScout conversations with a relevant report, not a count of affected orders. I did
not inspect attachment contents or bank-side records, so findings reflect what merchants and support reported in
the threads.

## Distinct problem patterns

1. **WooCommerce cancelled an order while the bank application was approved or visible — 6 conversations
(2023–2026).** Merchants reported approved installments appearing as cancelled, pending, or failed in WooCommerce;
one had to move an order to processing manually. Support explained that WooCommerce can cancel an unpaid order
after its stock-hold/payment timeout, while a missing or late bank callback can leave the site unaware of
approval. Support asked merchants to distinguish WooCommerce’s timeout from a plugin cancellation on bank refusal,
and to check live/test mode, the bank webhook URL, and the required T-Bank API password. In one clear case,
support identified the WooCommerce hold timeout; in others the suggested checks were the resolution path, and the
thread did not establish which setting fixed it. The bank application could remain approved even though the store
order had been cancelled.

2. **A later cancellation or rejection after signing did not change an order already moved to processing — 1
conversation (2026).** The buyer signed and the plugin treated the bank’s `signed` status as completion, moving
the order to processing. A subsequent bank cancellation/rejection arrived as a duplicate notification; the plugin
did not apply it to the already processed order. Support explained this status behavior and said the merchant had
to cancel the WooCommerce order manually. This is the clearest post-signing cancellation problem in the history;
the thread does not show a way to cancel the bank agreement through the plugin.

3. **Initial bank refusal left an order failed even if a partner bank later approved — 1 conversation (2026).**
The merchant said the first bank’s refusal put the WooCommerce order into failed, and approval from a partner bank
did not move it to processing. The merchant had to ask support to check the application manually. Support pointed
to the API password as necessary for retrieving the current application status; the thread does not confirm that
this resolved the partner-bank case.

## Auto-confirm, refunds, and limits

- No reviewed cancellation thread establishes that an **auto-confirm** setting was on or off. A 2025 thread about
automatic WooCommerce status changes after bank approval concerned a missing webhook/API password and an outdated
plugin version; it did not document an auto-confirm toggle.
- Refund requests in the search results were generally about refunding the plugin purchase or license, not
cancelling a buyer’s credit/installment application. I found no distinct support pattern showing the plugin
issuing a refund as the remedy for an application cancellation.
- The evidence distinguishes cancelling the WooCommerce order from cancelling the bank application: in the
reported cases, support guidance concerned status callbacks, WooCommerce timeouts, or manual order status changes.
The history does not establish a supported plugin action that cancels an already signed bank agreement.

## Related

- [T-Bank v2 decisions](decisions.md) — D5, the decision this history answers.
- [T-Bank v2 plugin design](../../specs/2026-10-27-tbank-v2-plugin-design.md) — §10.3 and §22.3 O1, where the history is applied.
- [Bank docs](d-docs.md) — the webhook statuses the patterns refer to.
