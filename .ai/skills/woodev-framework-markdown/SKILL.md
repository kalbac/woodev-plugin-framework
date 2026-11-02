---
name: woodev-framework-markdown
description: Guidelines for creating and modifying markdown files in Woodev Framework projects. Use when writing documentation, README files, or any markdown content.
---

# Woodev Framework Markdown Guidelines

This skill provides guidance for creating and editing markdown files in the Woodev Framework project.

## Critical Rules

1. **Always lint after changes** - Run `markdownlint --fix` then `markdownlint` to verify (for `docs-internal/` also `npm run lint:docs`)
2. **Run from repository root** - Ensures `.markdownlint.json` config is loaded
3. **Use UTF-8 encoding** - Especially for directory trees and special characters
4. **Follow Woodev markdown standards** - See configuration rules below

## Woodev Markdown Configuration

`.markdownlint.json` is the source of truth. It sets `"default": true` (every markdownlint rule on, with markdownlint's defaults) and switches **off**: MD013 (line length), MD024, MD028, MD031, MD032, MD033 (inline HTML), MD034, MD040, MD041, MD046, MD051 and MD060. Everything else is enforced with defaults — notably MD003 (ATX headings), MD007 (list indent: markdownlint default, 2 spaces), MD036 (no bold-as-heading) and MD047 (single trailing newline). Re-read the file instead of trusting a copy of the list. The CI `markdown-lint` workflow lints root and `website/` markdown only; `.ai/`, `docs-internal/`, `CLAUDE.md` and `AGENTS.md` are excluded from it.

Disabled rules are a relaxation, not a style: still put blank lines around fences and lists, and give every code fence a language — readers and `docs-internal/` conventions expect it.

`docs-internal/` has two extra gates beyond markdownlint: `npm run lint:docs` (structure, size caps, gotcha index, and **every relative link must resolve**) and the `## Related` section required on gotchas, ADRs and wiki pages. The rules are in `docs-internal/DOCS-SCHEMA.md`.

## Markdown Writing Guidelines

### Headings

```markdown
# Main Title (H1) - Only one per file

## Section (H2)

### Subsection (H3)

#### Minor Section (H4)
```

- Use ATX style (`#`) not underline style
- One H1 per file (usually the title)
- Maintain heading hierarchy (don't skip levels)

### Lists

**Unordered lists:**

```markdown
- Item one
- Item two
  - Nested item (2 spaces)
  - Another nested item
- Item three
```

**Ordered lists:**

```markdown
1. First item
2. Second item
3. Third item
```

**Important:**

- Nested items follow markdownlint MD007 (2-space indent by default; the config does not override it)
- Add blank line before and after lists
- Use `-` for unordered lists (not `*` or `+`)

### Code Blocks

**Always specify the language:**

````markdown
```bash
npx markdownlint-cli2 path/to/file.md
```

```php
public function process_order( int $order_id ) {
    // code here
}
```

```javascript
const result = calculateTotal(items);
```
````

**Common language identifiers:**

- `bash` - Shell commands
- `php` - PHP code
- `javascript` or `js` - JavaScript
- `typescript` or `ts` - TypeScript
- `json` - JSON data
- `sql` - SQL queries
- `markdown` or `md` - Markdown examples

**Code block rules:**

- Add blank line before the opening fence
- Add blank line after the closing fence
- Always specify language (never use plain ` ``` `)

### Inline Code

Use backticks for inline code:

```markdown
Use the `process_order()` method to handle orders.
The `$order_id` parameter must be an integer.
```

### Links

```markdown
[Link text](https://example.com)

[Internal link](../path/to/file.md)

[Link with title](https://example.com "Optional title")
```

### Tables

```markdown
| Column 1 | Column 2 | Column 3 |
|----------|----------|----------|
| Value 1  | Value 2  | Value 3  |
| Value 4  | Value 5  | Value 6  |
```

- Use pipes (`|`) for column separators
- Header separator row required
- Alignment optional (`:---`, `:---:`, `---:`)

### Directory Trees

**Always use UTF-8 box-drawing characters:**

```markdown
includes/
├── classes/
│   ├── admin/
│   │   └── class-create-order.php
│   └── frontend/
│       └── class-checkout.php
└── api/
    └── class-api-base.php
```

**Never use:**

- ASCII art (`+--`, `|--`)
- Spaces or tabs for tree structure
- Control characters

### Emphasis

```markdown
**Bold text** for strong emphasis
*Italic text* for regular emphasis
***Bold and italic*** for very strong emphasis
```

## Workflow for Editing Markdown

1. **Make your changes** to the markdown file
2. **Auto-fix linting issues:**

   ```bash
   markdownlint --fix path/to/file.md
   ```

3. **Check for remaining issues:**

   ```bash
   markdownlint path/to/file.md
   ```

4. **Manually fix** what remains (usually language specs for code blocks)
5. **Verify clean** - No output means success
6. **Commit changes**

## Common Linting Errors and Fixes

### MD007: List indentation

**Problem:**

```markdown
- Item
    - Nested (4 spaces)
```

**Fix:**

```markdown
- Item
  - Nested (2 spaces)
```

### MD031: Code blocks need blank lines

**Problem:**

````markdown
Some text
```bash
command
```
More text
````

**Fix:**

````markdown
Some text

```bash
command
```

More text
````

### MD032: Lists need blank lines

**Problem:**

````markdown
Some text
- List item
````

**Fix:**

````markdown
Some text

- List item
````

### MD036: Emphasis as heading

**Problem:**

```markdown
**Example: Using bold as a heading**

Some content here
```

**Fix:**

```markdown
#### Example: Using a proper heading

Some content here
```

### MD040: Code needs language

**Problem:**

````markdown
```
code here
```
````

**Fix:**

````markdown
```bash
code here
```
````

## Special Cases

### CLAUDE.md Files

CLAUDE.md files are AI assistant documentation:

- Must be well-formatted for optimal parsing by AI
- Follow all markdownlint rules strictly
- Use clear, hierarchical structure
- Include table of contents for long files

### README Files

- Start with H1 title
- Include brief description
- Add installation/usage sections
- Keep concise and scannable

### CHANGELOG.md

**Important:** `CHANGELOG.md` is **auto-generated by git-cliff** from Conventional Commits.

- **DO NOT** manually edit `CHANGELOG.md`
- Write commits in Conventional Commits format
- `git-cliff` generates CHANGELOG on release

### Plugin Documentation

- Follow WordPress Plugin Documentation standards
- Include screenshots and screenshots of settings
- Include FAQs
- Include changelog (auto-generated)

## Troubleshooting

### File Shows as "data" Instead of Text

**Problem:** File is corrupted with control characters

**Fix:**

```bash
tr -d '\000-\037' < file.md > file.clean.md && mv file.clean.md file.md
file file.md  # Verify shows "UTF-8 text"
```

### Linting Shows Unexpected Errors

**Problem:** Not running from repository root

**Fix:**

```bash
# Always run from root
cd /path/to/plugin_folder
markdownlint path/to/file.md

# NOT like this
markdownlint /absolute/path/to/file.md
```

### Auto-fix Doesn't Work

**Problem:** Some issues require manual intervention

**Fix:**

- Language specs for code blocks must be added manually
- Long lines may need manual rewrapping
- Some structural issues require content reorganization

## Notes

- Most markdown issues are auto-fixable with `markdownlint --fix`
- Always run markdownlint from repository root
- UTF-8 encoding is critical for special characters
- CLAUDE.md files must pass linting for optimal AI parsing
- **CHANGELOG.md is auto-generated** — do not edit manually
