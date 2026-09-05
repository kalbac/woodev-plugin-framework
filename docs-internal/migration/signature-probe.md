# Signature-compatibility probe (card #767)

> Script: `scripts/signature-probe.mjs` · npm script: `probe:signature`

## Why this exists

Repointing a plugin class at a stricter v2 framework base class (e.g. `Woodev_Plugin` →
`Shipping_Plugin`) fatals at PHP **class-declaration time** — before WordPress boots, before any
test runs, before a single request is served — the moment two override rules disagree between the
plugin class and the base chain. A green unit suite cannot see this: Brain Monkey/Mockery build a
*double*, not a real subclass, so the incompatible override is never declared and never checked
(see `docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md`). A human review that
greps for TYPE mismatches also misses `final` — the single most expensive miss in the manual pass
that produced this card (`Shipping_Method::calculate_shipping()` is `final`; the plugin's override
of it is a fatal for a completely different reason than a type mismatch).

The probe reads PHP **source only** — no WordPress bootstrap, no Composer autoload, no
`class_exists()`. That is the point: it has to work before the plugin is even capable of loading.

## What it checks

Given a **subject** class (the plugin class, before migration) and a **base** class (the nearest
v2 base it is about to be repointed at), the probe:

1. Loads the subject class's own declared methods.
2. Walks the base class's `extends` chain automatically, following `namespace`/`use` resolution,
   stopping the moment an `extends` target can't be found in the indexed roots (e.g. `WC_Shipping_Method`,
   `WC_Integration` — external WooCommerce core classes, out of scope for this probe).
3. For every subject method whose name also exists somewhere in that chain, compares it against
   the **nearest** ancestor declaration (nearest-ancestor-wins: if `Woocommerce_Plugin` overrides
   something `Woodev_Plugin` declared, `Woocommerce_Plugin`'s version is what the subject is
   actually being compared against).

It reports three sections:

- **FATALS** — things PHP rejects at class declaration:
  - the base declares the method `final` and the subject overrides it;
  - the subject narrows visibility (base `public`/`protected`, subject a stricter level);
  - `static` mismatch in either direction;
  - an incompatible return type (including the base declaring a return type the subject omits
    entirely — PHP treats "no return type" as incompatible with a declared one, not as "anything
    goes").
- **UNIMPLEMENTED ABSTRACTS** — abstract methods declared anywhere in the base chain with no
  implementation anywhere in the subject (an abstract satisfied by a nearer concrete class in the
  chain itself does not count — only ones the subject itself must still supply).
- **PARAMETER DIVERGENCE** — reported separately, never fatal. A parameter-list mismatch (count,
  resolved types, defaults) is usually a bug, but PHP's own fatal-or-not line falls only on the
  four FATALS rules above.

It additionally reports (outside the three official sections, since it is not something the card
asked to count, but it is exactly the trap that cost real time in s115/s116):

- **SHADOWED BASE-PRIVATE METHODS** — a base method declared `private` is **not** a conflict (private
  methods aren't inherited), but if the subject declares a same-named `public`/`protected` method
  believing it overrides the base, it silently doesn't: the base calls its own private method
  internally via `$this->name()`, which PHP resolves to the *declaring class's* private method, never
  the subject's. Example: `Shipping_Method::should_send_cart_api_request()` is `private`; the plugin's
  own public method of the same name never runs via that path.

Short class names and fully-qualified names are resolved per-file (via that file's own `namespace`
and `use` statements) before comparison — `?Settings\Shipping_Integration` in the base and
`?\Woodev\Framework\Shipping\Settings\Shipping_Integration` in the subject are the same type, not a
divergence.

## Usage

```bash
# the edostavka-vs-Shipping_* triple this card was built to reproduce (default, no args needed)
npm run probe:signature

# a specific pair
node scripts/signature-probe.mjs \
  --pair "path/to/Subject.php:SubjectClass=path/to/Base.php:BaseClass"

# multiple pairs in one run (repeat --pair), against non-default indexed roots
node scripts/signature-probe.mjs \
  --root woodev --root plugins-reference \
  --pair "plugin/class-a.php:A=woodev/base/class-base-a.php:BaseA" \
  --pair "plugin/class-b.php:B=woodev/base/class-base-b.php:BaseB"
```

⚠ **The default pairs need `plugins-reference/`, and that directory is GITIGNORED.** It holds donor
plugins as a local convenience; it does not exist in CI or in a fresh clone. Run with no arguments
there and the probe says which pairs it skipped and exits 0 — it is not broken, it has nothing to
look at. For the same reason the one jest test that reads those real files skips itself when they
are absent, while every other test builds its own PHP fixtures in a temp directory and so runs
everywhere. This cost a red CI job on the probe's own PR: `npm run test:js` was green locally,
because a local checkout has the donor plugins and CI never does.

`--root` (repeatable) controls which directories are scanned to resolve the base's `extends` chain
beyond the file given in `--pair` — it defaults to `woodev` and `plugins-reference`. `--pair` is
`subjectFile:SubjectClass=baseFile:BaseClass`; both file paths are relative to the repo root (or
absolute).

## Reading the report

```
=== SubjectClass (path/to/subject.php) vs BaseClass chain ===
  chain: BaseClass -> Ancestor -> AncestorOfAncestor -> WC_Something (external, source not available — stopped)

  FATALS (N)
    ✗ [rule] SubjectClass::method() — detail

  UNIMPLEMENTED ABSTRACTS (N)
    ✗ DeclaringClass::function name(...) — no implementation anywhere in SubjectClass

  PARAMETER DIVERGENCE (N, not fatal)
    ~ SubjectClass::method() — DeclaringClass::signature vs override: signature

  SHADOWED BASE-PRIVATE METHODS (N, not fatal — dead code)
    ! SubjectClass::method() has the same name as DeclaringClass::method() (private) — ...
```

A `chain: ... -> X (external, source not available — stopped)` line is expected and not an error —
it means the probe reached a class outside the indexed roots (WooCommerce/WordPress core) and
correctly stopped rather than guessing at code it cannot read.

**`FATALS` and `UNIMPLEMENTED ABSTRACTS` are must-fix before the repoint compiles at all.**
`PARAMETER DIVERGENCE` and `SHADOWED BASE-PRIVATE METHODS` won't fatal PHP, but are worth a look —
the shadow case in particular reads as "I overrode this" when it silently didn't.

## Known limitation: not a full PHP parser

This is a hand-rolled, comment/string-stripped, brace-depth-aware scanner scoped to what this
repo's plugin classes actually look like — not the full PHP grammar. It does not attempt: grouped
`use { }` imports, union/intersection return types beyond a `|`-split literal comparison,
first-class callable syntax, or attributes containing `{`/`}`. If a future subject file uses one of
these and the probe's counts look wrong, that is a probe limitation to fix, not evidence that the
plugin is fine — verify by hand (or load it once on a real WordPress install; the fatal happens at
declaration, so merely loading is enough) before trusting a silent pass.

## Card #767 acceptance figures — and where this probe's own count differs

The card recorded expected figures from a prior (unpublished) mechanical diff, measured in s116:

| class | fatals (card) | fatals (this probe) | unimplemented abstracts |
|---|---|---|---|
| main class (`WC_Edostavka_Shipping` vs `Shipping_Plugin` chain) | 7 | **6** | 1 / **1** |
| shipping method (`WD_Edostavka_Shipping` vs `Shipping_Method`) | 3 | **3** | 5 / **5** |
| integration (`WC_Edostavka_Integration` vs `Shipping_Integration`) | 3 | **2** | 2 / **2** |
| **TOTAL** | **13** | **11** | **8** / **8** |

The unimplemented-abstract count matches the card exactly (8/8), and the shipping-method fatal
count matches exactly (3/3, including catching the `calculate_shipping()` `final` miss the card
specifically calls out). The two places this probe finds fewer fatals than the card were
independently checked against a real `php` interpreter — not just against this script's own logic
— before being accepted as correct:

```
$ php -r '
class A { public function foo(): void {} }
class B extends A { public function foo() {} }
new B();'
PHP Fatal error:  Declaration of B::foo() must be compatible with A::foo(): void
```

Every fatal this probe reports for the main class and the integration class was verified this way
(a minimal standalone `A`/`B` pair reproducing the exact modifier/type shape, run through `php`
directly — no WordPress, no autoload). All 6 + 2 = 8 hold up. For the main class, all 18
name-overlaps between `WC_Edostavka_Shipping` and the `Shipping_Plugin` → `Woocommerce_Plugin` →
`Woodev_Plugin` chain were enumerated by hand and cross-checked against the probe's own output;
none beyond the 6 already counted produces a fatal under real PHP semantics. Likewise for
integration's 4 name-overlaps (`__construct` excluded — PHP does not enforce LSP compatibility on
constructors — `admin_options` matches, `init_form_fields`/`is_configured` are the 2 fatals).

Two candidates the card's own s116 narrative dismissed as "not conflicts" —
`get_checkout_handler()` and `get_webhook_handler()`, on the reasoning "returns `?null`, which the
base explicitly allows" — are counted here as real fatals, and are verified as such above: the
`php` engine checks the *declared* return type at class-declaration time, never the *runtime*
value a method happens to return. A base method declaring `: ?Checkout\Checkout_Handler` and a
subject override with no return type at all is exactly the `A`/`B` shape above, regardless of what
either method returns. The card's reasoning conflated "must this always return a real object" — a
semantic question, correctly answered "no" — with "must the declared return type be covariant with
the base's", a syntactic one whose answer does not depend on the first. Why the s116 diff came out
at 13 cannot be established: the script that produced the figure was never committed, so it cannot
be re-run.

**Second, independent confirmation (s117).** The main-class and integration counts were re-derived
from scratch by a different route — a separate throwaway scanner listing every method declaration
on both sides with its modifiers and return type, then comparing them by hand — without consulting
this probe's output. It arrived at the same **6** and the same **2**. The seventh candidate on the
main class is `includes()`, and it is not a fatal: `Shipping_Plugin::includes()` is declared
`private`, so it is not inherited and the plugin's public `includes()` cannot conflict with it —
it is the SHADOWED case this probe reports separately. Anyone re-opening this question should
start there, because it is the one overlap that looks like a conflict in a plain name-and-type
diff and is not one.

So the working figure is **11 / 8**, not 13 / 8. If a re-measurement does find a missing fatal, it
is not among the 22 name-overlaps enumerated above (18 for the main class, 4 for integration) —
every one of those was checked by hand against the chain's actual declared modifiers and types,
twice, by two different people.
