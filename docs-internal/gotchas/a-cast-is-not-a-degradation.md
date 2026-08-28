# Gotcha: [php/filter-returns] — A cast satisfies the type and breaks the behaviour; `absint()` on garbage is `0`
> Tags: php, filters, hooks, degradation, background-jobs | Session: s102

## What happens

The standing rule from #599/#613 is *degrade to a safe default, never throw, never disable a
protection*. It is easy to read a CAST as satisfying that rule — the crash is gone, the type is
right — and three separate proposals in the #599 audit did exactly that. Two of them turn a fatal
into something worse: a silently disabled subsystem.

The worked case, `Woodev_Background_Job_Handler`:

```php
$finish = $this->start_time + absint( apply_filters( "{$this->identifier}_default_time_limit", 20 ) );
// …
if ( time() >= $finish ) { $return = true; }   // "time exceeded"
```

A plugin returning `'twenty'` gives `absint( 'twenty' ) === 0`, so `$finish === $start_time`, so the
FIRST check answers "time exceeded" — and **every background job stops before processing a single
item.** On cron. Silently. No fatal, nothing in the log, no symptom except work that never happens.

The sibling, `schedule_cron_healthcheck()`, registers `'interval' => MINUTE_IN_SECONDS * 0` and an
`Every 0 Minutes` label in the admin.

Same shape elsewhere: `(array) 'boom'` is `[ 'boom' ]`, a one-element list keyed `0`. For the pickup
map's i18n filter that means the map renders with **every label it asks for missing** — a broken
panel for the customer, and nothing logged. For the Settings API's `in_array()` sinks it means a
scalar is quietly registered as a valid setting type.

## Root cause

A cast answers the question "is this the right TYPE now?". The rule asks a different question: "is
this a SAFE VALUE?". `0`, `[]` and `[ 'boom' ]` are all type-valid and none of them is the
pre-filter value. The pre-filter value is the only one known to be safe, because it is what the
framework itself computed.

`absint()` is genuinely correct where `0` is a meaningful answer — it is used that way in
`class-woodev-job-batch-handler.php:239`, which is why the audit proposed it here by analogy. The
analogy fails wherever `0` means "off".

## Fix

❌ Wrong — type-valid, behaviour destroyed:

```php
$interval = absint( apply_filters( "{$this->identifier}_cron_interval", $interval ) );
$types    = (array) apply_filters( "woodev_{$id}_settings_api_setting_types", $types, $this );
$strings  = array_map( 'strval', (array) $strings );
```

✅ Correct — discard the return and keep what the framework computed:

```php
$filtered_interval = apply_filters( "{$this->identifier}_cron_interval", $interval );
$interval          = is_numeric( $filtered_interval ) && (int) $filtered_interval > 0 ? (int) $filtered_interval : $interval;

$filtered_types = apply_filters( "woodev_{$id}_settings_api_setting_types", $types, $this );
$types          = is_array( $filtered_types ) ? $filtered_types : $types;

$filtered_strings = apply_filters( 'woodev_pickup_map_i18n', $strings, $this->plugin_id );
$strings          = is_array( $filtered_strings ) ? array_map( 'strval', $filtered_strings ) : $strings;
```

Note the numeric guards reject **non-positive** values too, not just non-numeric ones: `-1` disables
the job runner exactly as effectively as `0` does.

**And the test has to prove the degradation, not just the absence of a crash.** The first version of
these tests asserted `assertSame( 0, $schedules[...]['interval'] )` and `assertTrue( $handler->time_exceeded() )`
— pinning the broken behaviour as if it were intended. A hostile-return test must assert the
PRE-FILTER value came back.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the other way a green test pins a fiction
- [the-classic-adapter-reverts-a-select-the-location-cascade-owns](the-classic-adapter-reverts-a-select-the-location-cascade-owns.md) — same family: guarding on the wrong property satisfies the check and misses the case
