# Gotcha: [tooling/windows] — The Bash tool's *quoted* heredoc still eats backslashes
> Tags: tooling, windows, measurement | Session: s123

## What happens

You write a helper script through the Bash tool with `cat > file <<'EOF' … EOF`. The delimiter is
quoted, which in POSIX `sh` means the body is passed through **literally** — no parameter
expansion, no command substitution, no backslash processing. That is the whole reason to quote it.

It does not hold here. Backslashes are consumed on the way to disk, and every `\\` in the body
arrives as `\`:

```php
$relative = str_replace( '\\', '/', $path );     // what you wrote
$relative = str_replace( '\', '/', $path );      // what landed on disk
```

PHP then reports:

```text
PHP Parse error: syntax error, unexpected token "{", expecting ")" in i18n-scan.php on line 37
```

**Line 37 is `if ( '{' === $t ) {` — a line that is perfectly valid and was never touched.** The
unterminated `'\'` on line 24 swallowed the rest of that string literal, and the parser only fell
over thirteen lines later. So the error points at innocent code, and the natural reaction is to
rewrite the innocent code. Bisecting the file (`sed -n '1,36p' > t2.php`) is what finally names
line 24, and `od -c` on that one line is what proves it:

```text
$ sed -n '24p' i18n-scan.php | od -c | head -2
0000000  \t   $   r   e   l   a   t   i   v   e       =       s   t   r
0000020   _   r   e   p   l   a   c   e   (       '   \   '   ,       '
```

One backslash where the source had two.

## Root cause

Not diagnosed at the shell level, and it does not need to be: whatever wraps the command before
`sh` sees it processes escapes, so the quoted-heredoc guarantee is not available through this tool.
The observable rule is what matters — **a quoted heredoc through the Bash tool is not a literal
channel.** It is the same family as the already-recorded trap that backticks inside
`python -c "…"` are executed by the shell and substituted into the file being written (s122), and
as Git-Bash mangling Cyrillic arguments (s76/s115): the text you typed is not the text that arrives.

## Fix

❌ Wrong — any script body containing `\`, backticks, or `$`, written through a heredoc:

```bash
cat > scan.php <<'PHPEOF'
$rel = str_replace( '\\', '/', $path );
PHPEOF
```

✅ Correct — use the **Write tool** for script files. It writes bytes, so there is no shell in the
path at all:

```text
Write(file_path="…/scan.php", content="$rel = str_replace( '\\', '/', $path );")
```

✅ And when a literal backslash is unavoidable in something that *must* go through the shell, write
it without typing one — `chr( 92 )` in PHP, `chr(92)` in Python, `DIRECTORY_SEPARATOR` where it fits:

```php
$relative = str_replace( chr( 92 ), '/', $relative );
$name     = ltrim( $t[1], chr( 92 ) );          // strip a leading namespace separator
```

**Verify, do not assume it worked.** A parse error is the lucky case: it fails loudly. A script that
merely *behaves* differently — a regex whose `\d` became `d`, a path split that no longer splits —
runs green and lies. After writing any file through a shell, read back the one line you care about
with `od -c` before trusting the run.

**The general rule:** when a tool promises a literal channel and the output is wrong, suspect the
channel before the code. The failure surfaced at line 37 and lived at line 24; a session that trusts
the reported line number spends its time editing correct code. This is the third recorded shape of
"the shell rewrote my argument" on this machine, and the reason the standing advice is to measure
with a script FILE rather than an inline payload.

## Related

- [git-bash-mangles-cyrillic-in-curl-arguments](git-bash-mangles-cyrillic-in-curl-arguments.md) — the same class, different character set, and the one that persisted mojibake into a GitHub board
- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — MSYS rewriting absolute paths, including in `docker exec`
- [a-po-merge-that-drops-obsolete-entries-still-looks-well-formed](a-po-merge-that-drops-obsolete-entries-still-looks-well-formed.md) — count in and out rather than trusting a clean-looking output
