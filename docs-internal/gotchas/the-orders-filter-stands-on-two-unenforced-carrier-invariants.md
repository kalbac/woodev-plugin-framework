# Gotcha: [shipping/orders] — The orders filter stands on two carrier invariants nothing enforces
> Tags: meta_query, orders-query, delivery-status, shipping | Session: s140

## What happens

`Orders_Query::delivery_status_meta_clauses()` builds a correct `meta_query` only while two facts
about the data hold. Neither is checked anywhere, and a test built on the same reading of the data
stays green when either breaks: s139's specification oracle in
`ShippingOrdersQueryRowSemanticsTest` diverged from the code in exactly one place, and that place
turned out to be the first invariant, not a defect (#924).

## Root cause

1. **A carrier writes only its OWN status meta.** The positive `delivery_status=<canonical>` form is
   one `IN` clause per participating provider, on that provider's `status_meta_key`, and it is NOT
   bound to the provider's marker — binding costs one join per participant. An order that carries
   carrier A's marker and carrier B's status meta matches B's filter. The negative forms are bound,
   because unbound there the OR across providers matched the whole table (#837 defect 2, gotcha
   `a-negative-meta-clause-or-ed-across-providers-matches-every-order`).
2. **An order carries AT MOST ONE carrier marker** (operator, 26.09.2026: «на практике нет; для этого
   нужна реализация мультидоставки, которой у нас нет» — YAGNI). Today's shapes are correct on a
   multi-marker order anyway; what depends on the rule is a cheaper negative form that subtracts ONE
   set of orders. That form is an existential over the providers present on the order only when
   there is one — #919 measured 4/16/64 divergences over 16/64/128-order universes, every one of
   them multi-marker. And the rule is NOT true of the code: `edostavka` writes a marker per package
   (`class-wc-edostavka-checkout.php:930`), `yandex` on export, gating only on its own shipping being
   present (`class-order.php:100`) — measured in #919's research note, carried on #928.

   **#928 wave 3 (s140) did NOT take that form.** The measurement (wave 2) showed the wall was the
   marker SCOPE — one un-predicated join per OR-ed marker `EXISTS`, `~d^N` — not the negation, so
   the whole tree now resolves to ids by one flat statement (`Orders_Id_Resolver`) and reaches the
   datastore as `post__in`. Each negative clause stays bound to its own marker inside that tree, so
   the id form is equivalent to the `meta_query` on a multi-marker order too and leans on
   invariant 2 no more than the joins did. The wave-1 guard (`Orders_Registry::report_multiple_markers()`,
   `_doing_it_wrong()` under `WP_DEBUG`) and the single-marker oracle universe stand on the
   operator's ruling, not on a need of the query.

## Fix

Before changing either clause family, decide which invariant the new shape leans on, and say so in
the method's docblock (it lists both). A form that needs invariant 2 must ship with the guard #928
specifies — `_doing_it_wrong()` under `WP_DEBUG` when an order carries more than one marker — and an
oracle test whose universe EXCLUDES multi-marker orders explicitly, not by accident. Since #928 the
place to change a clause's MEANING is the tree (`Orders_Query::build_meta_query()`), which the oracle
gate walks through the `resolve_order_ids()` seam; the place to change its COST is the compiler
(`Orders_Id_Resolver::compile()`), whose shape `ShippingOrdersIdResolverTest` pins.

❌ Wrong — reasoning «the carriers never overlap» and dropping a binding or a scope on that belief:

```php
// "One carrier per order, so the set subtraction is the same filter."
$args['exclude'] = $ids_with_status_x; // silently narrower on a multi-marker order
```

✅ Correct — name the invariant, guard it, and keep the oracle honest about what it enumerates:

```php
// Relies on invariant 2 (docblock of delivery_status_meta_clauses(), #924).
// Guarded by _doing_it_wrong() under WP_DEBUG (#928); the oracle universe
// excludes multi-marker orders by construction.
```

## Related

- [a-negative-meta-clause-or-ed-across-providers-matches-every-order](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — why the negative forms are bound
- [a-relation-key-does-not-tell-the-and-wrapper-from-a-single-meta-query-part](a-relation-key-does-not-tell-the-and-wrapper-from-a-single-meta-query-part.md) — the same test, the addressing trap
- [919-subquery-seam](../research/2026-09-26-919-subquery-seam/README.md) — the quantifier measurement
- [928-form-measurement](../research/2026-09-26-928-form-measurement/README.md) — why the wall was the scope, and the id form that replaced it
