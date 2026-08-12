# A test proving a credential does NOT leak will fail the credential scanner if its placeholder looks real

**Namespace:** `[testing/unit]`
**Discovered:** 2026-08-12 (s68, PR-B of the location-provider layer)

## The trap

The strongest form of a "no leak" assertion is: plant a **distinctive** credential value, serialize
whatever ships to the client, and grep the serialized output for that literal value. Asserting on
key names instead is weaker — it passes while the value leaks through some field nobody thought to
check.

The instinct is then to make the placeholder look like a real credential. That is precisely what
breaks: CI runs `gitleaks detect --no-git --config .gitleaks.toml` as the **Secret scan** job, and
its `generic-api-key` rule fires on entropy. A placeholder shaped `SECRET-` + an eight-character hex
run + `-DISTINCTIVE` scored **entropy 4.004** and tripped it — so the test that proves the secret is
not exposed failed the build for exposing a secret.

Note which half of the value each side needs:

- the assertion needs **uniqueness** — a string that cannot occur in the config by accident;
- the scanner reacts to **entropy** — randomness, hex runs, base64-looking chunks.

They are separable. Spend uniqueness, spend no entropy.

## ❌ Wrong

```php
// Shape only — the literal is deliberately NOT reproduced here; see below.
$token  = 'TOKEN-<8 hex chars>-DISTINCTIVE';
$secret = 'SECRET-<8 hex chars>-DISTINCTIVE';   // gitleaks: generic-api-key, entropy 4.004
```

**The scanner reads documentation too.** The first version of this very file pasted the offending
literals verbatim, and the next CI run failed the **Secret scan** job pointing at *this file*, line
30 — the write-up of the trap reproduced the trap. Whenever a gotcha is about something a scanner
matches, record the SHAPE and the score, never the string.

## ✅ Correct

```php
$token  = 'token-value-that-must-never-reach-the-client';
$secret = 'secret-value-that-must-never-reach-the-client';
```

Still unique enough to grep for in the serialized payload, with nothing for an entropy rule to bite.

## Do not "fix" it by allowlisting

Adding the test file to `.gitleaks.toml`'s allowlist makes the scanner blind to that path forever —
including to a real credential a future session pastes into a fixture there. The scanner is right
about the string; the string is what should change.

## Related

- [[settings-sensitive-secret-empty-skip-is-client-side]] — the other half of credential handling:
  how a sensitive setting behaves when left blank.
- `docs-internal/GOTCHAS.md` → `[testing/*]`, `[build/*]`.
