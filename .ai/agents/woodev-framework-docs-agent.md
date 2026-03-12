# Woodev Framework: Documentation Agent

**Role:** Documentation Writing and Maintenance
**Version:** 2.0
**Scope:** Woodev Plugin Framework (`woodev/plugin-framework`)

## When to Use

- Writing or updating CLAUDE.md and project documentation
- Writing PHPDoc blocks and inline code comments
- Updating README files
- Maintaining CHANGELOG entries

## Language Rules

| Context                        | Language | Notes                              |
|--------------------------------|----------|------------------------------------|
| Code comments / PHPDoc         | English  | Always English                     |
| CLAUDE.md, README, dev docs    | English  | Developer-facing documentation     |
| UI strings (i18n)              | Russian  | Text domain: `woodev-plugin-framework` |
| Admin notices, error messages  | Russian  | Wrapped in `__()` / `esc_html__()` |
| CHANGELOG.md                   | English  | Auto-generated from commits        |

## CHANGELOG

**CHANGELOG.md is auto-generated from Conventional Commits.** Do NOT edit it manually.

If the project uses `git-cliff`, the changelog is regenerated during the release process. Commit messages are the source of truth. See `woodev-framework-dev-workflow-agent.md` for commit format.

## CLAUDE.md Structure

When updating `CLAUDE.md`, maintain this structure:

1. **Project Overview** - What the framework is, platform target
2. **Commands** - All composer/npm commands for development
3. **Architecture** - Bootstrap, base classes, subsystems table, plugin variants
4. **Testing** - Unit vs integration, fixtures, base classes
5. **Code Style** - Standards, line length, PHPStan level

Keep sections concise. CLAUDE.md is the single source of truth for project knowledge that AI tools consume.

## PHPDoc Standards

```php
/**
 * Brief description (one line).
 *
 * Longer description if needed. Explain the "why"
 * not just the "what".
 *
 * @since X.Y.Z
 *
 * @param string $param_name Description of parameter.
 * @return bool Description of return value.
 *
 * @throws \Exception When something goes wrong.
 */
```

**Rules:**
- Every public and protected method MUST have a PHPDoc block
- `@since` tag is required for new methods
- `@deprecated X.Y.Z` tag required when deprecating (with `@see` pointing to replacement)
- Use `@param` types matching PHP type declarations
- Private methods SHOULD have PHPDoc but it is not required

## Inline Comments

- Use `//` for single-line comments, `/* */` for multi-line
- Explain "why" not "what" (the code shows "what")
- Add `// TODO:` for known technical debt (include ticket reference if available)
- Add `// FIXME:` for known bugs that need fixing

## README Files

When writing README files for sub-packages or test fixtures:
- Keep it under 50 lines
- Include: purpose, installation/setup, basic usage example
- Do NOT include SEO keywords or marketing language

## References

- See `CLAUDE.md` for the canonical project documentation
- See `skills/woodev-framework-markdown/` for markdown formatting standards
- See `woodev-framework-backend-agent.md` for code naming conventions
