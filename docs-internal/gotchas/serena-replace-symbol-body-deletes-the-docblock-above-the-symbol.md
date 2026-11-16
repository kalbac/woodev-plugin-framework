# Serena `replace_symbol_body` deletes the docblock above the method it replaces

**Topic:** `[tooling/*]` · **Discovered:** s232 (2026-11-16, #1795)

## Symptom

After `replace_symbol_body` on a PHP method whose replacement text began at the `private function …` line, `git diff` showed the method's
whole `/** … */` block gone — phpcs then reports `Missing doc comment` and the `@throws` / `@return` contract is lost without any error
from Serena. Seen on macOS; the same call on Windows also rewrites line endings
([serena-replace-content-eol-flip](serena-replace-content-eol-flip.md)).

## Root cause

The symbol's range that Serena replaces starts at the docblock, not at the `function` keyword, and the replacement body is taken
verbatim: a body without a docblock replaces the docblock with nothing.

## ✅ Correct

Include the docblock in the `body` you pass, or edit the body with `replace_content` (a literal or a tight regex on the lines you change)
and leave the docblock alone. Run `git diff` after the edit and look for a vanished `/** */`.

## Related

- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md)
