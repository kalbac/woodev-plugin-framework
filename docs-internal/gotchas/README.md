# Gotchas — Atomic Detail Files

Each gotcha is documented as a standalone Markdown file in this directory.
The master index is at `../GOTCHAS.md`.

## Gotcha File Format

```markdown
# {Title} — {one-line summary}

**Root cause:** {why this happens}

## ❌ Wrong

```php
// code that doesn't work
```

## ✅ Correct

```php
// code that works
```

## Notes

{additional context, edge cases, references}

## Related

- [[../GOTCHAS.md]] — master gotcha index
- [[../DOCS-SCHEMA.md]] — doc format rules
```

## Creation Rules

1. One gotcha = one file
2. Slug: lowercase, hyphenated, namespace prefix (e.g., `framework-bootstrap-loading.md`)
3. Always include ❌ and ✅ code examples
4. Always include `## Related` section
5. Add index entry to `../GOTCHAS.md` immediately
