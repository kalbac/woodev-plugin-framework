# A mutation sweep over branch conditions reads as complete and is not

**Namespace:** `[testing/unit]` · **Discovered:** s45 (2026-07-31), repeatedly across SP-5

## The trap

Mutation testing was the highest-yield practice on the SP-5 branch — it found real defects, not
just coverage gaps. But it produced a specific false signal, three times, in the same shape:

> "Ran 14 mutants. All 14 killed on the first pass."

Every one of those reports was **true and misleading**. The implementer had mutated **branch
conditions** — flip a comparison, delete a guard, invert an `if`. Reviewers then mutated **values
and content** and found survivors immediately:

| Task | Implementer's sweep | What a value/content mutant found |
|---|---|---|
| `Constraint_Checker` | 14/14 killed (all control flow) | Swapping the two `sprintf` arguments survived — the customer would be told a 15 kg order exceeds a 20.5 kg limit. Dropping the g→kg conversion survived twice. |
| `Pickup_Controller` | 6 mutants, 1 honest survivor | 3 more survived: the fail-closed `default:` arm, `q` never proven to reach the query, an uncapped id |
| `pickup-mount` | 7/7 killed | 4 survived — three were i18n keys the JS read that PHP never emitted, invisible because the JS carried byte-identical Russian defaults |

## Why branch-only sweeps pass

A branch mutant changes *whether* code runs, and tests almost always assert *that something
happened*. A value mutant changes *what* the code produces while the control flow stays identical —
so it only dies against an assertion on the **exact value**. `assertNotNull( $verdict['reason'] )`
kills nothing; `assertSame( 'Вес заказа 20.50 кг…', $verdict['reason'] )` kills four mutants.

The i18n case is the sharpest: duplicating a PHP string as a JS default makes every key mismatch
invisible, because the assertion passes on the fallback. **A default that equals the real value is
an assertion killer.**

## ❌ Wrong

> Deleted each `return null;` and each guard one at a time. All killed. The suite is
> mutation-resistant.

## ✅ Correct

Sweep three categories, and say which you ran:

1. **Branch** — invert/delete conditions. (What everyone does.)
2. **Value** — swap arguments, change a constant, drop a unit conversion, rename a lookup key,
   change a serialisation separator, alter a status code.
3. **Content** — replace a user-facing message wholesale; assert the rendered string, not its
   non-emptiness.

Two further rules learned the same way:

- **A mutant killed by an unrelated guard is not covered.** On `Point_Query`, two mutants died
  because the test payload also tripped the span cap. The guard under test was gone and nothing
  noticed. Isolate the input so only the target guard can reject it.
- **Boundary-*acceptance* matters as much as rejection.** Tests checked `±91`/`±181` but never
  exactly `±90`/`±180`, so a mutant that rejected a legitimate pole-touching bbox survived.

## Related

- [[phpcs-does-not-enforce-line-length]] — the other "green means nothing" trap from the same branch
- [[phpunit-multiple-file-args]] — a third way a passing run can be lying to you
