# Gotcha: [i18n/classification] — Classify an i18n string by its render path, not by its file path
> Tags: i18n, measurement, shipping | Session: s103

## What happens

You need to know which translatable strings reach the **storefront** and which stay in the admin,
because the rule that governs them differs. You bucket them by directory — `shipping-method/checkout/`
and `shipping-method/pickup/` are the checkout, so their strings are customer-facing — and report a
volume from that.

The number is wrong, and it is wrong in a way that looks careful. The s102 measurement on **#567**
produced **104 frontend strings**; classifying the same 305 cyrillic calls by their **enclosing
method** and verifying each at its sink produced **65**. Nearly 40% of the estimate was admin text
sitting in a checkout directory, and the card carried that number into two handoffs.

## Root cause

A directory says who OWNS a file, not who READS its output. The framework deliberately keeps a
subsystem's admin settings next to its frontend code — so one file legitimately holds both.

The four shapes that broke the path heuristic on #567, each verified at its sink:

| Sites | Where | What it actually is |
|---|---|---|
| 42 | `Checkout_Field_Settings`, `Location_Settings`, `Pickup_Map_Settings` `::register_settings()` | `'name'` on `register_setting()` and `'tooltip'` on `register_control()` — admin labels |
| 11 | `Setting::get_validation_error()` | reaches `Abstract_Settings::validate_values()` → `Woodev_REST_API_Settings_Page::save()` — the React settings screen |
| 22 | `Pickup_Controller` / `Location_Controller` error texts | never rendered: `pickup-mount.js` maps `reason.code` to an i18n string from `get_js_config()`, and its docblock says so outright |
| 2 | `class-pickup-handler.php:1524,1527` | the map's accent-colour setting, in a file whose other 49 strings are the checkout map |

The REST row is the sharpest one: a controller that serves the checkout is not the same thing as a
controller whose TEXT reaches the checkout. `location-cascade.js` renders `body.message` verbatim in
exactly ONE place — the D7 stale-record notice — and everywhere else the client substitutes its own
string. Assuming otherwise would have rewritten 22 strings nobody can read.

## Fix

❌ Wrong — bucket by path, then count:

```bash
grep -rc "__(" woodev/shipping-method/checkout/ woodev/shipping-method/pickup/
```

✅ Correct — map each call to its enclosing method, then classify the METHOD by where its return
value is rendered, and follow the sink until it reaches a screen or provably does not:

```
305 cyrillic calls  →  107 enclosing methods  →  per-method verdict
```

The three sinks worth knowing here:

- `wc_add_notice()` — customer sees it (`Pickup_Handler::add_error()`);
- `wp_localize_script()` into a `frontend/` script — customer sees it
  (`Pickup_Handler::get_js_config()`, `Checkout_Config::location_i18n_strings()`);
- a REST error body — customer sees it **only** where the client renders `message`; grep the
  frontend JS for `.message` before assuming either way.

**The general rule:** a classification heuristic that is 85% right produces a number that is 60%
wrong, and reads as measured. When the buckets carry different obligations, verify the boundary
cases at the sink rather than sampling the middle.

## Related

- [a-concatenated-msgid-is-invisible-to-a-single-literal-scanner](a-concatenated-msgid-is-invisible-to-a-single-literal-scanner.md) — the other #567 measurement gap, found in the same pass
- [comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts](comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts.md) — the s100 i18n measurement that was also wrong on its first pass
- [measure-a-gate-where-the-gate-can-actually-fire](measure-a-gate-where-the-gate-can-actually-fire.md) — same family: measuring the proxy instead of the thing
- [grep-the-sink-not-one-spelling-of-it](grep-the-sink-not-one-spelling-of-it.md) — follow the sink, not the name
