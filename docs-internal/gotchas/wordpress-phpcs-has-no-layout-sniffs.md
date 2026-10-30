# The WordPress PHPCS standard does not enforce code layout — layout sniffs are added explicitly in the ruleset

**Namespace:** `[tooling/phpcs]` · **Discovered:** s205 (2026-10-30) · **Status:** confirmed

## What happens

`composer phpcs` reported 0 violations on code with `foo(); }` (the method's closing brace glued to its last statement), no blank
line between methods, constants and properties, and one-line member docblocks (`/** @var string plugin id */`). The `WordPress`
standards have no sniff for any of it, and `woocommerce/woocommerce-sniffs` adds none either (and its PHPCompatibility 9.3.5 aborts
on PHP >= 8.1, so it is not used). A green phpcs says nothing about layout.

## Fix

`phpcs.xml.dist` adds the layout sniffs explicitly: `Squiz.WhiteSpace.ScopeClosingBrace`, `Squiz.WhiteSpace.FunctionSpacing`
(one blank line between methods, none before the first or after the last) and `Squiz.WhiteSpace.MemberVarSpacing` (one blank line
between properties). `phpcbf` fixes them. Nothing checks docblock shape: write multi-line WordPress docblocks (summary, blank ` *`,
tags) by hand. Do not drop a sniff that fights another rule — tune its properties.

In a template, `ScopeClosingBrace` wants `endif;` on its own line, which would print a line break into the HTML. Keep the
byte-identical output and suppress that one line with `// phpcs:ignore Squiz.WhiteSpace.ScopeClosingBrace.ContentBefore -- <reason>`
(`woodev/shipping-method/admin/views/html-admin-order-metabox.php`).

Scope is the ruleset's `./woodev`; `tests/` is excluded from phpcs and was not reformatted.

## Related

- [phpcs.xml.dist](../../phpcs.xml.dist) — the ruleset
- [phpcs-does-not-enforce-line-length](phpcs-does-not-enforce-line-length.md) — the other thing phpcs does not gate
