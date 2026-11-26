# Gotcha: [framework/contracts] — a method or argument added to an interface plugins implement fatals released plugins
> Tags: contracts, interface, multi-version, marketplace | Session: card #1999 (26.11.2026)

## What happens

A framework change extends an interface that a plugin implements — a new method, or a new argument, even an
optional one:

```php
interface Card_Lifecycle {
	// ❌ one more argument, with a default
	public function read_import( Account $account, string $remote_task_id, string $context, array $offer_ids = [] ): array;
}
```

A released plugin still declares three arguments. Its class file now fails to compile:

```
PHP Fatal error: Declaration of Released_Adapter::read_import(...) must be compatible with Card_Lifecycle::read_import(...)
```

The default value does not help. Updating the plugin «in the same release window» does not help either.

## Root cause

The resolver loads framework classes from the **highest registered copy for the whole fleet** (AGENT-RULES.md Rule 3). A site
where only ANOTHER plugin was updated runs the old marketplace plugin against the new interface. The marketplace flow wiring
builds the adapter's objects on ordinary requests, so the site goes down, not one screen. Ozon Seller 2.0.0 declares
`backwards_compatible => 2.0.0`, so nothing deactivates it first.

Brain Monkey tests do not show it: the framework's own test doubles are updated in the same commit.

## The way that works

```php
// ✅ a separate, optional interface; the framework asks with instanceof
interface Article_Keyed_Lifecycle extends Card_Lifecycle {
	public function read_import_by_articles( Account $account, array $offer_ids, string $context ): array;
}

$by_article = $lifecycle instanceof Article_Keyed_Lifecycle ? $lifecycle : null;
```

Precedents: `Dictionary_Label_Lookup`, `Export_Grouping`, `Import_Deadlines`, `Article_Keyed_Lifecycle`. The same holds for
`Marketplace_Adapter` itself and every contract it returns, since Ozon Seller 2.0.0 reached shops. Interfaces only the framework
implements (`Task_Ledger`) are free to change.

A spec may still ask for a changed signature (the Wildberries design did for `read_import()`): check who implements it in a
released plugin before following it.

## Related

- [a-stricter-base-class-fatals-on-signatures](a-stricter-base-class-fatals-on-signatures.md) — the same PHP rule on the plugin-author side
- [ADR-005](../adr/005-platform-v2-clean-break-policy.md) — «internal APIs may break» predates the released v2 plugins
- [Marketplace module](../wiki/marketplace-admin.md#export-of-grouped-cards-and-marketplaces-without-a-task-number-1999)
