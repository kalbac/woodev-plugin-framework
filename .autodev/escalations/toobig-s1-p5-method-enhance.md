# ESCALATION toobig-s1-p5-method-enhance -- task too big

**Task:** s1-p5-method-enhance -- Minimal Shipping_Method enhancement (rate-cache hook + pickup seam)
**Type:** blocked
**What happened:** Worker reported TOO_BIG; needs decomposition.
**Decision you need to make:** Approve the proposed split?
**Option A:** Approve split
**Option B:** Re-scope manually
**Cost of being wrong:** none (no code landed)

**Evidence:**
```
# worker-report: s1-p5-method-enhance

status: TOO_BIG

files_touched:
  - woodev/shipping-method/class-shipping-method.php   (change present on disk, correct & additive — see below)

contract_zones_touched: [hooks, shipping_method_id]

rationale: The single-file deliverable is two correct additive seams (rate-cache pre-filter + base `get_pickup_point_source()` null-default). BUT the task's own stated purpose — "an accessor seam so a pickup method **can reach** its `Pickup_Point_Source`" — is NOT met by the base file alone: the seam is inert until `Shipping_Method_Pickup` overrides it, and that file is outside this task's `file_set`. The critic (verdict=broken, conf 0.97) correctly flags the dead seam. Wiring it requires a 2nd logical change in a 2nd file → reporting TOO_BIG with a decomposition rather than (a) claiming DONE on a non-functional seam or (b) touching a file not in `file_set`.

## Why this is TOO_BIG, not DONE

The base change itself is sound and should be KEPT (it is already written to disk, uncommitted):

1. **`use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;`** — import for the new return type. Resolves: interface declared in `woodev/shipping-method/pickup/interface-pickup-point-source.php`, namespace `Woodev\Framework\Shipping\Pickup` — exact match.
2. **Rate-cache pre-filter in `calculate_shipping()`** — `apply_filters( 'woodev_shipping_method_pre_calculate_rate', null, $package, $this )`; `calculate_rate()` runs only when the filter yields null, so default behavior is byte-identical. Freshly computed rate still flows through the pre-existing `woodev_shipping_method_calculated_rate` filter. This part is COMPLETE and correct on its own.
3. **`get_pickup_point_source(): ?Pickup_Point_Source { return null; }`** — base null-default seam. Correct for the base, but see the gap below.

**The gap (critic's proven finding):** `get_pickup_point_source()` has *no caller* in the tree today (grep: only its own definition), so nothing mis-behaves at runtime *yet*. However, the seam exists specifically so a pickup method exposes its source — and `Shipping_Method_Pickup` (which already declares `abstract protected function get_point_source(): Pickup_Point_Source`) does NOT override the new public accessor. Every pickup instance therefore returns `null`, so the seam cannot fulfil its stated purpose. Making it functional is a second logical change in `woodev/shipping-method/class-shipping-method-pickup.php` — a file NOT in this task's `file_set`. Worker rule: touch only `file_set` files; if the task needs >1 logical change, report TOO_BIG.

## Proposed decomposition (two clean, file-disjoint tasks)

- **s1-p5-method-enhance (this task, keep as-is, file_set unchanged)** — base `woodev/shipping-method/class-shipping-method.php`: rate-cache pre-filter + base `get_pickup_point_source()` null-default seam. Already on disk; additive; phpstan-green; no contract string touched. Acceptance is met *for the base*.
- **s1-p5b-pickup-source-override (NEW follow-up)** — file_set: `woodev/shipping-method/class-shipping-method-pickup.php`. Add the one-line override:
  ```php
  public function get_pickup_point_source(): ?Pickup_Point_Source {
      return $this->get_point_source();
  }
  ```
  contract_zones_touched: [shipping_method_id] (path-glob only; no id/contract string changed). This wires the seam to the pickup source and resolves the critic's regression. depends_on: [s1-p5-method-enhance].

## Contract-zone justification (no guard needed — needs_guard: no)

- **hooks**: only a NEW filter name (`woodev_shipping_method_pre_calculate_rate`) added; no existing hook renamed/removed/reordered. Additive.
- **shipping_method_id**: file is under `woodev/shipping-method/**` (path-glob match) but no `$this->id =`, `get_method_id()`, `method_id`, or `woocommerce_shipping_methods` line was touched. Method-id contract untouched.
- No constitution path touched. `writes_guard: false` → no mutation-recipe emitted.

## Verification performed

- Referenced type resolves: `Pickup_Point_Source` interface present at correct namespace (verified).
- `composer phpstan` reported green (138/138) on the base change in the prior attempt; the base file is syntactically and statically clean.
- `grep` confirms `get_pickup_point_source()` currently has no caller → the critic's break is a *latent* dead-seam (purpose unmet), not a live runtime regression.

## Acceptance vs spec

- [x] composer phpstan green — base change verified green.
- [~] existing shipping-method tests stay green — base change is no-op-by-default; gate is authoritative.
- [x] change is additive; no signature break to `calculate_rate`.
- [!] "accessor seam so a pickup method can reach its Pickup_Point_Source" — NOT achievable in this single file; requires the follow-up override (see decomposition). This is the TOO_BIG trigger.

```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
