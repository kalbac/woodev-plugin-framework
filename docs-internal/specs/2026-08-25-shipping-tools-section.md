# Секция «Инструменты» на вкладке «Доставка» — design

> Settled with the operator on 25.08.2026 (s90), after the first attempt at #488 D8 shipped the two
> merchant actions as two separate fields-less connection sections. That shape was wrong and never
> reached him for review — this spec replaces it. Written in English per `DOCS-SCHEMA.md`; the
> operator's own wording is quoted where it is the decision.

## What this is

One place, last on the «Доставка» tab, where **actions** live — as opposed to settings, which is
what every other section on that tab holds. Both #488 D8 actions move here, and it is built as a
**registry**: the framework registers tools, and any carrier plugin can register its own and have it
appear in the same section.

**Operator, 25.08.2026:** *«в закладке доставка, самая последняя секция должна быть "Инструменты", в
этой секции сразу две кнопки… В будущем, мы туда будем добавлять и другие инструменты как от
фреймворка, так и любой плагин карьера может зарегистрировать свой инструмент и он тоже появится
здесь же.»*

## D1. Our own mechanism, in WooCommerce's shape

**Decision:** own registry, own execution, own REST — but the tool DESCRIPTOR mirrors WooCommerce's
`woocommerce_debug_tools` key-for-key.

**Why not build on WC's mechanism directly** (it was the operator's first instinct, and was weighed
rather than dismissed):

- WC's list is **global and context-free**. These tools belong to the shipping tab and to a
  provider; in `wc-status&tab=tools` they would surface for anyone with `manage_woocommerce`, away
  from the settings they act on.
- **The neighbourhood changes the meaning.** WC's tools page is a repair/debug surface — «Очистить
  транзиенты», «Пересоздать таблицы». «Очистить список популярных городов» is an ordinary merchant
  action, not a repair, and reads as "something is broken" next to those.

**Why the descriptor is WC-shaped anyway:** a plugin author who already knows `woocommerce_debug_tools`
recognises ours on sight, and a future bridge onto WC's own page becomes a small adapter rather than
a redesign. Keys taken from WC's own template
(`includes/admin/views/html-admin-page-status-tools.php`) and its REST controller:

| Key | Meaning |
|---|---|
| `name` | the tool's title |
| `desc` | what it does, shown under the title |
| `button` | the button's label |
| `callback` | what runs |
| `disabled` | render the control, refuse the action |
| `status_text` | why it is disabled, or a live status line |
| `selector` | an optional input rendered BEFORE the button — see D2 |

**A correction worth recording, because it was mine:** the first analysis claimed WC's tools have no
form and simply run on click. That is wrong. `html-admin-page-status-tools.php:41-54` renders a
`selector` (`description` / `class` / `name` / `placeholder` / `search_action`) for tools that
declare one — `Regenerate the product attributes lookup table` and `Delete an Inbox Notification`
both use it. The operator caught it.

## D2. The provider is chosen EXPLICITLY, in the tool

**Decision:** both popular-settlements tools carry a `selector` listing providers, defaulting to the
active one but always visible. The merchant states which provider's list they are checking or
clearing.

**This is the decision that removes a whole mechanism, not just a control.** The first
implementation inferred the provider from persisted state while the settings form could be showing
a staged, unsaved change — so a merchant who switched the provider without saving and pressed
«Очистить» destroyed the list of the *still-persisted* provider. A Codex critic found it, and it was
closed with a staged-vs-persisted guard plus a widened REST allow-list.

With the provider stated explicitly there is nothing to infer, so **the guard is not merely
unnecessary — keeping it would block a legitimate explicit choice.** It goes, and so does everything
that existed only to serve it (see D5).

**Default to the active provider, always shown.** No default means an extra click on every action;
a hidden default puts the same ambiguity back in a new place.

## D3. The capability gate (spec D4 of the popular-settlements design) moves, it does not vanish

- Only providers declaring `CAPABILITY_RESOLVE_KEY` appear in the selector.
- The chosen provider's capability is **re-checked server-side at action time**. A control's presence
  is a view, never an authorisation.
- If NO provider has the capability, the two tools are absent entirely — not present-and-disabled.
  That is the standing D4 rule: no capability, no popular list, nothing about it in the UI.

`disabled` + `status_text` exist in the descriptor for tools whose unavailability is worth
explaining; the popular-settlements pair is not one of them, because D4 says absent.

## D4. Placement

Last section of the «Доставка» tab, after «Поля» / «Карта» / «Локация» — i.e. appended in
`Shipping_Settings_Tab::build_sections()`
(`woodev/shipping-method/settings/class-shipping-settings-tab.php`).

The section exists only when at least one tool is registered. An empty «Инструменты» is worse than
no «Инструменты».

## D5. What the first attempt leaves behind

The branch `feat/488-slice3-d8-merchant-actions` is NOT continued — it carries the wrong shape in its
foundation. A fresh branch takes only what survives.

| From the first attempt | Fate |
|---|---|
| `Popular_Settlement_Verifier::sweep()`, `Popular_Settlement_Store::clear_provider()` and their tests | **kept** — untouched by presentation |
| The D4 capability gate | **kept**, relocated per D3 |
| Two fields-less connection sections | dropped — this spec replaces them |
| Staged-vs-persisted provider guard | dropped — D2 removes its premise |
| `get_connection_ids()` added to the public `Woodev_Settings_Connection_Test` | dropped. It existed only because the actions lived in the connection seam, and it was a breaking change to a contract shipped plugins implement |
| Composite routing by connection-id ownership | dropped with it |
| The REST allow-list widening, and the credential-boundary fix that repaired it | dropped together — the fix repaired a regression the widening introduced, and the widening existed only for the guard |

**Nothing here is wasted work that should have been avoided.** The critic rounds that produced the
guard and the routing were correct about the code as it stood; what changed is the shape underneath
them.

## D6. Registration seam

A tool is registered with an owner (the framework, or a carrier plugin) and a provider scope where
one applies. The shape must let a carrier plugin register without knowing anything about the
location layer — that layer is one consumer of this section, not its reason for existing.

Deliberately NOT decided here, because it is an implementation-time question the way D8's own seam
check was: whether registration is a filter (WC's idiom, familiar) or a method on an existing
registry (this codebase's idiom, typed). Pick it against the code and record the reason.

## What this does NOT do

- It does not register anything into `woocommerce_debug_tools`, and does not render our tools on
  WC's status page (D1). The descriptor shape keeps that option open.
- It does not add a confirmation dialog. Whether a destructive tool needs one is a question for the
  tool, not for the section.
- It does not touch the block checkout — like everything else in this layer.

## Related

- [2026-08-24-popular-settlements-design.md](2026-08-24-popular-settlements-design.md) — D8, the two
  actions this section hosts; D4, the capability gate
- [../AGENT-RULES.md](../AGENT-RULES.md) — «фреймворк = механизм + контракт + хуки»
- `woodev/shipping-method/settings/class-shipping-settings-tab.php` — the tab this section joins
