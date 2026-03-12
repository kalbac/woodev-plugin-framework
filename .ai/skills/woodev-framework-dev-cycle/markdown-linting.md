# Markdown Linting

Guidelines for linting and formatting markdown files in Woodev Framework projects.

---

## Rules

All markdown files must follow the `woodev-framework-markdown` skill guidelines.

### Headings

- Use ATX-style headings (`#` not `===`)
- Maintain proper hierarchy (no skipping levels)
- Add space after `#`

```markdown
<!-- Correct -->
# Heading 1

## Heading 2

### Heading 3

<!-- Wrong -->
Heading 1
=========

##Heading 2

#### Skipped to 4
```

### Code Blocks

- Always specify language
- Use backticks (```) not tildes (~~~)

````markdown
<!-- Correct -->
```php
echo 'Hello';
```

<!-- Wrong -->
```
echo 'Hello';
```

~~~markdown
code
~~~
````

### Links

- Use descriptive link text
- No "click here"

```markdown
<!-- Correct -->
See the [installation guide](INSTALL.md) for details.

<!-- Wrong -->
Click [here](INSTALL.md) for details.
```

### Lists

- Use `-` for unordered lists
- Use `1.` for ordered lists
- Add blank line before and after lists

```markdown
<!-- Correct -->
Some text:

- Item 1
- Item 2

More text.

<!-- Wrong -->
Some text:
- Item 1
- Item 2
More text.
```

### Tables

- Align columns for readability
- Include header separator row

```markdown
<!-- Correct -->
| Column 1 | Column 2 | Column 3 |
|----------|----------|----------|
| Data 1   | Data 2   | Data 3   |
| Data 4   | Data 5   | Data 6   |
```

### Line Length

- Keep lines under 120 characters
- Break long lines at word boundaries

---

## Commands

```bash
# Lint markdown files
npx markdownlint "**/*.md"

# Fix auto-fixable issues
npx markdownlint --fix "**/*.md"

# Lint specific file
npx markdownlint path/to/file.md
```

---

## Common Issues

### Trailing Spaces

Remove trailing whitespace at end of lines.

### Multiple Consecutive Blank Lines

Use maximum one blank line between content.

### Inconsistent Heading Style

Use consistent heading style throughout document.

### Missing Blank Lines

Add blank lines:

- Before and after headings
- Before and after lists
- Before and after code blocks
- Between paragraphs

---

## VS Code Settings

Recommended `.vscode/settings.json`:

```json
{
    "markdownlint.config": {
        "default": true,
        "MD013": { "line_length": 120 },
        "MD033": false
    },
    "editor.trimTrailingWhitespace": true,
    "editor.insertSpaces": false
}
```

---

## Pre-commit Checklist

Before committing markdown files:

1. Run `npx markdownlint path/to/file.md`
2. Fix all reported issues
3. Verify with `npx markdownlint path/to/file.md`
4. Check rendered preview in editor
