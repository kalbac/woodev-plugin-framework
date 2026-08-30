# gotcha: a setting can carry a `'name'` label and never render — `register_control()` is the only proof

**Namespace:** `[settings-api/rendering]`
**Discovered:** s108 (2026-08-31)

## What happens

You need to know which options a merchant actually sees on a settings tab. The obvious move is to
grep for their labels:

```bash
grep -n "'name'\s*=>\s*__(" woodev/shipping-method/location/class-location-settings.php
```

Eight results come back, so eight options are on the tab. **Seven are.** The eighth —
`SETTING_DEFAULT_LOCALITY_NEEDS_REPICK`, «Зафиксированная локация требует повторного выбора» — has a
label, a type and a default, and is never drawn at any policy. The file says so in as many words:

> …it stays registered and writable through the generic `Woodev_Abstract_Settings` accessors,
> **it simply never renders, at any policy**.

## Root cause

This settings API separates two registrations, and only the second one is about the screen:

| call | declares |
|---|---|
| `register_setting()` | the STORAGE — option key, type, default, and a `'name'` label used for validation messages and internal reporting |
| `register_control()` | the CONTROL — what the merchant sees and can change |

A setting with the first and not the second is a real, writable option with no UI. That is a
deliberate pattern here, not an oversight: the live equivalent of this particular flag is surfaced
through another field's own description
(`Location_Provider_Registry::apply_default_locality_status_note()`).

Because there is no control, there is also no tooltip slot — nothing to fill in.

## ❌ Two discriminators that look right and are not

```php
// ❌ a label proves storage, not rendering
grep "'name' => __("

// ❌ get_owned_setting_ids() is NOT the render list either
public function get_owned_setting_ids(): array {
    return array_merge(
        [ SETTING_ACTIVE_PROVIDER, SETTING_DEFAULT_LOCALITY_POLICY, SETTING_DEFAULT_LOCALITY_RECORD ],
        array_keys( $this->provider_fields )
    );
}
```

The second one is the sharper trap. It omits `SETTING_FIELD_MODE_REGION`,
`SETTING_FIELD_MODE_SETTLEMENT`, `SETTING_ALLOW_CUSTOM_SETTLEMENT` and `SETTING_ADDRESS_SUGGESTIONS`
— all four of which DO render. Ownership and visibility are different questions, and there is more
than one handler contributing controls to one tab.

## ✅ The discriminator

```php
// a setting renders if and only if something calls register_control() for it
register_control( SETTING_X, \Woodev_Control::TYPE_SELECT, [ 'tooltip' => … ] );
```

Sweep for `register_control(` across every settings handler that contributes to the tab, not just
the one that looks like it owns the subject.

## Why it is worth a gotcha

s108 asserted twice, an hour apart, that a setting was visible when it was not — and both times the
claim went onto a GitHub card as a fact the operator would decide by. The operator caught the first
(«этого пункта нет и быть в UI не может»); the second was a wrong discriminator offered as the fix
for the first. A label is the most natural thing to grep for and the least reliable thing to trust.

## Related

- [[a-plausible-inference-written-as-fact-is-the-dangerous-one]] — the failure mode this is an instance of
- [[section-empty-setting-ids-renders-all-fields]] — the other direction: declaring NO setting ids renders everything
- [[woodev-setting-get-value-is-cached-not-a-live-option-read]] — the same class of "registered ≠ what you think" in the read path
