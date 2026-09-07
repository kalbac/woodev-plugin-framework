# Gotcha: [php/inheritance] — A base constructor that ASSIGNS what the subclass just set, and the coincidence that hides it
> Tags: php, inheritance, shipping, measurement | Session: s123

## What happens

A subclass declares something in its constructor and calls the parent:

```php
public function __construct( int $instance_id = 0 ) {
    $this->supports = [ 'shipping-zones', 'instance-settings', Shipping_Method::FEATURE_BOX_PACKING ];

    parent::__construct( $instance_id );   // ← throws the line above away
}
```

The base does exactly what it looks like it does:

```php
$this->supports = [ self::FEATURE_SHIPPING_ZONES, self::FEATURE_INSTANCE_SETTINGS ];
$this->init_form_fields();                 // reads supports; the subclass's flag is already gone
```

`supports_box_packing()` answers `false`. No warning, no error, no failing test.

**What makes this one nasty is not the assignment — it is why nobody saw it for a year.** Every
one of the four fixtures in this repo declared *exactly* `[ 'shipping-zones', 'instance-settings' ]`,
which is precisely what the overwrite put back. The value was identical, so the loss was
invisible. The bug could only ever surface for a caller that declared something DIFFERENT — and
until #567 needed the box-packing setting on screen, none did.

## Root cause

Two mechanisms compounding, and the second is what turns a wart into a dead branch:

1. **Assignment, not merge.** PHP runs the subclass constructor body first, so by the time the
   parent constructor executes, `$this->supports` already holds the subclass's answer. Assigning
   to it there is a silent discard.
2. **The only window that matters is inside the parent constructor.** The base calls
   `init_form_fields()` immediately after, and that is what builds the `packing_algorithm`
   control. A subclass therefore cannot repair the damage afterwards: by the time it regains
   control, the form fields are already built.

Measured on the rig — all three routes a plugin could take, and the third is not documented
anywhere:

| how the feature is declared | `supports_box_packing()` | control on the settings screen |
|---|---|---|
| `$this->supports = [ … ]` before `parent::__construct()` | **false** | no |
| `add_support()` after construction | true | **no** |
| `add_support()` **plus a manual `init_form_fields()` re-run** | true | yes |

The middle row is the one the PUBLIC docs recommend (`docs/shipping-method.md` → Best Practices:
"`FEATURE_SHIPPING_CLASSES` — opt-in via `add_support()`"). So **neither documented path produced
the setting**, and the `supports_box_packing()` / `supports_shipping_classes()` branches of
`init_form_fields()` were dead code for every plugin that has ever existed.

## Fix

❌ Wrong — assignment, which cannot distinguish "declared nothing" from "declared something":

```php
$this->supports = [ self::FEATURE_SHIPPING_ZONES, self::FEATURE_INSTANCE_SETTINGS ];
```

✅ Correct — merge, and be explicit about the framework-default case:

```php
// WC_Shipping_Method::$supports defaults to [ 'settings' ]; a subclass that declared NOTHING
// arrives carrying that, and it is dropped deliberately — keeping it would flip
// has_settings() to true for a method with no instance id, which is a different change.
$declared = is_array( $this->supports ) && [ 'settings' ] !== $this->supports ? $this->supports : [];

$this->supports = array_values(
    array_unique( array_merge( [ self::FEATURE_SHIPPING_ZONES, self::FEATURE_INSTANCE_SETTINGS ], $declared ) )
);
```

**The general rule, and it is about testing as much as about inheritance:** when a base class
writes a property its subclasses also write, the test that would catch a clobber has to use a
value the base does NOT produce. A fixture that declares the defaults proves nothing — it passes
identically whether the base merges or overwrites. Ask of any such fixture: *would this test still
pass if the line under test were deleted?* Here the answer was yes, four times over.

The counterpart trap, on the same seam: a feature flag that only affects behaviour built during
the constructor cannot be added afterwards. If a flag has to be settable later, whatever it gates
must be resolved lazily, not once.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why the pinning tests for this live in the integration suite: the unit stand-in never runs the framework constructor at all
- [a-registered-setting-without-a-control-never-renders](a-registered-setting-without-a-control-never-renders.md) — the settings-API sibling: declaring a thing is not the same fact as it reaching a screen
- [measure-a-gate-where-the-gate-can-actually-fire](measure-a-gate-where-the-gate-can-actually-fire.md) — same family: a check that cannot fail is not a check
- [the-rig-runs-en-us-so-no-translation-ever-renders-there](the-rig-runs-en-us-so-no-translation-ever-renders-there.md) — the other half of why #567's visual pass was blocked
