# A plain object is not an insertion-ordered map, and not a safe one

**Namespace:** `[js/*]`
**Found:** s59, 2026-08-09 — by adversarial review of #234's point pool, before it shipped.

## The claim that was wrong

The pool accumulating pickup points across viewport listings was a plain `{}` keyed by `point.id`,
with this reasoning written into its own docblock:

> Insertion order is meaningful: it is what `trimPointPool()` evicts by when a domain has bounded
> the pool. **Plain object key order preserves insertion order for string keys, which every
> `point.id` is coerced to on the way in.**

The bolded half is false, and it is false in exactly the case this carrier produces.

## Trap 1 — integer-like keys are ordered NUMERICALLY, and first

`Object.keys()` returns own properties in this order, per spec:

1. every **array-index-like** key (a canonical numeric string), in **ascending numeric order**;
2. every other string key, in insertion order;
3. symbols.

Russian Post's point ids are numeric strings — `'111543'`, `'45637'`, `'26600'`. So `String(id)`
does not save you: the coercion produces exactly the keys that get reordered.

```js
var pool = {};
pool['100'] = a;   // seen first
pool['200'] = b;   // seen second
pool['50']  = c;   // seen third — the NEWEST

Object.keys( pool );        // [ '50', '100', '200' ]  ← not insertion order
```

An eviction pass that walks `Object.keys()` and drops from the front therefore evicts **the newest
point**, not the oldest — the marker the customer just panned to, while stale ones survive. The
pool still holds the right COUNT, so nothing looks broken.

## Trap 2 — `__proto__` as a data key silently isn't one

```js
var pool = {};
pool['__proto__'] = point;      // sets the PROTOTYPE, creates no own property
Object.keys( pool );            // []  ← the point vanished
'__proto__' in pool;            // true — even on an EMPTY {}
```

A carrier id is opaque data. `'__proto__'`, `'constructor'`, `'toString'` are all legal strings, and
the `in` membership test used to decide "is this id new?" answers `true` for all of them against a
plain `{}`, so such a point is never even recorded as seen.

## ✅ Correct

```js
// Null-prototype: every key is a plain data key, `in` means what it says.
var pool  = Object.create( null );
// Insertion order tracked explicitly — never inferred from key enumeration.
var order = [];

function add( point ) {
    var id = String( point.id );

    if ( ! ( id in pool ) ) {
        order.push( id );
    }

    pool[ id ] = point;
}

function values() {
    return order.map( function( id ) { return pool[ id ]; } );
}
```

## ❌ Wrong

```js
var pool = {};
pool[ String( point.id ) ] = point;
// …later, believing this is oldest-first:
Object.keys( pool ).forEach( evictWhileOver );
```

## Why no test caught it

The first eviction tests used ids `'A'`, `'B'`, `'C'` — non-numeric, so they enumerate in insertion
order and the buggy code passes. The fixture was **poorer than production in the one dimension that
mattered**: the live carrier's ids are numeric, the invented ones were not. Same family as
`an-invented-fixture-tests-your-assumptions-not-the-carrier` (s57).

The regression tests now use `'100'`, `'200'`, `'50'` deliberately, and were confirmed to go red by
mutating `trimPointPool()` back to `Object.keys()`.

## The general rule

If you need a map that is **ordered** or that holds **opaque external keys**, a plain `{}` gives you
neither. Use `Object.create( null )` plus an explicit order array (this codebase's shipped JS is
ES5, no `Map`), or `Map` where the file's language level allows it — `Map` fixes both traps at once,
since it preserves true insertion order and does not consult the prototype chain.

## Related

- [an-invented-fixture-tests-your-assumptions-not-the-carrier.md](an-invented-fixture-tests-your-assumptions-not-the-carrier.md) — the same "fixture poorer than production" shape
- [jest-toequal-empty-array-ignores-undefined.md](jest-toequal-empty-array-ignores-undefined.md) — another assertion that passes over a real gap
- [per-viewport-cache-is-unbounded-by-construction.md](per-viewport-cache-is-unbounded-by-construction.md) — why the pool needed a bound seam at all
- Issue #234 · spec `docs-internal/specs/2026-08-09-sp5-viewport-point-accumulation-design.md`
