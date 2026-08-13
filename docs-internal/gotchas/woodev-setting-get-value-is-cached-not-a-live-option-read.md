# `Woodev_Setting::get_value()` returns a cached property, so `update_option()` mid-request is invisible to it

**Topic:** `[framework/settings]`
**Discovered:** s71 (13.08.2026), while probing the related-list mode on the rig

## The trap

`Woodev_Setting::get_value()` returns `$this->value`, loaded once when the setting was built — it is **not** a live `get_option()` read. So a probe, a test, or any code that does

```php
❌ update_option( 'woodev_location_field_mode', 'related-list' );
   // ...and then, in the SAME request, expects the settings surface to report the new mode
```

sees the old value and concludes the feature is broken, or worse, concludes it works when it does not.

```php
✅ $settings->update_value( 'field_mode', 'related-list' );   // goes through the registry, refreshes the cached value
```

## Why it matters beyond tests

This is the shape that makes a rig probe lie. A probe that flips an option with `update_option()` and then renders the checkout in the same request is measuring the PREVIOUS configuration while believing it measured the new one — and the output looks entirely plausible either way. Any rig measurement that depends on changing a setting must either go through the registry's own writer or happen in a fresh request.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — the same family of "both halves are correct, the join is not"
