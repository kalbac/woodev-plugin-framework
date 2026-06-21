# [framework/autoload] Framework classes must be in the generated classmap, or they WSOD on a real vendored boot

> Namespace: `[framework/*]` · Added: s27 (2026-06-21)

## The trap

Since s27 the framework has a **hand-written runtime autoloader** (`Woodev_Framework_Autoloader`, `woodev/class-framework-autoloader.php`) — **no Composer in shipped plugins** (see [[feedback_no_composer_in_shipped_plugins]]). It resolves classes via a **generated map**, `woodev/class-map.php`, produced by `php bin/generate-class-map.php`.

In **tests**, Composer's classmap autoloader is present, so any framework class resolves regardless of whether it is in `woodev/class-map.php`. In a **real vendored boot** (a plugin shipping the framework), there is NO Composer — only this autoloader + the explicit `includes()` require-chains. So a class that is missing from (or stale in) `class-map.php` and not eagerly required by `includes()` will **fail to load → "class not found" fatal / WSOD on first boot**. Same failure class as `box-packer-interface-unwired-in-includes`.

## Correct / incorrect

- ✅ After adding, renaming, or moving any framework class/interface/trait file, **regenerate**: `php bin/generate-class-map.php`, and commit the updated `woodev/class-map.php`.
- ✅ The guard is `tests/unit/ClassMapCompletenessTest.php` — it fails if a declared symbol is **missing** from the map OR if the map path no longer matches the file that declares it (catches **renames/moves**, whose stale path the autoloader silently skips via its `is_readable` guard → downstream fatal).
- ❌ Do NOT hand-edit `woodev/class-map.php` (header says GENERATED).
- ❌ Do NOT assume "tests pass → it boots": Composer masks missing-map entries in tests.

## Mechanics worth knowing

- The resolver registers the autoloader against the **winning** framework copy (highest version, first after the `usort`) on the first `load_plugins()` iteration, **before** any typed plugin class (`extends ...`) parses. That ordering is what lets a plugin declare its type by `extends` alone (no `capabilities` hint).
- `autoload()` returns silently for non-framework classes and `is_readable`-guards the require, so a stale entry degrades to a miss, not a `require` fatal — but the class still never loads.
- Files loaded explicitly and therefore **excluded** from the map: `bootstrap.php`, `loader.php`, `class-map.php`, `class-framework-autoloader.php`.

## Multi-version forward-tolerance (B-2) — handled

Removing `capabilities` (s27) evolved the loader protocol, but this does **not** create a mixed-fleet hazard, because framework **classes always load from the highest registered copy** for the whole fleet (the resolver registers the autoloader against the winning/highest path), regardless of which copy wins the bootstrap class rendezvous. So a newer plugin never breaks against an older rendezvous winner; the at-risk party is an *older* plugin running against a newer framework, which the existing `backwards_compatible` min-version guard (`resolver:148-153`) deactivates-with-notice — same as v1. The removed `capabilities` protocol was never released, so it cannot break any deployed plugin.

Two standing rules keep this true (see `AGENT-RULES.md` Rule 3): (1) every loader definition MUST set `version` + `backwards_compatible`; (2) the registration contract is additive-only from v2.0.0 (older rendezvous-winner resolver must always read a newer plugin's registration).

## Related

- [[feedback_no_composer_in_shipped_plugins]]
- Spec: `docs-internal/specs/2026-06-21-plugin-type-autoloader-design.md`
- Sibling WSOD gotcha: `box-packer-interface-unwired-in-includes.md`
