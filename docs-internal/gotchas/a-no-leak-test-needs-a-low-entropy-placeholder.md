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
its `generic-api-key` rule fires on entropy. A placeholder like `SECRET-9b2d4f6a-DISTINCTIVE`
(entropy 4.0) trips it, so the test that proves the secret is not exposed fails the build for
exposing a secret.

Note which half of the value each side needs:

- the assertion needs **uniqueness** — a string that cannot occur in the config by accident;
- the scanner reacts to **entropy** — randomness, hex runs, base64-looking chunks.

They are separable. Spend uniqueness, spend no entropy.

## ❌ Wrong

```php
$token  = 'TOKEN-3f7c9a1e-DISTINCTIVE';
$secret = 'SECRET-9b2d4f6a-DISTINCTIVE';   // gitleaks: generic-api-key, entropy 4.004
```

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
