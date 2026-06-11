---
id: s8-p1-envelope-verifier-normalize-site
title: Generic Ed25519 envelope verifier + woodev_normalize_site() (shared §4-claims/webhooks primitive)
phase: S3.3 webhooks + §4 signing — crypto primitive (webhooks-spec §2, need-license-spec §4.1/§4.2, plan task s8-p1)
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/functions-license-authority.php
  - woodev/licensing/class-license-envelope-verifier.php
  - woodev/class-plugin.php
  - tests/unit/NormalizeSiteTest.php
  - tests/unit/LicenseEnvelopeVerifierTest.php
depends_on: []
contract_zones_touched: [ crypto_primitive, public_function_name ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass; baseline 337/1107 grows only)
  - "NEW guarded global function woodev_normalize_site( string $url ): ?string in woodev/functions-license-authority.php (if !function_exists guard — multi-version safe, recorded exception to OOP-only). Implements need-license-spec §4.2 steps 0-6 EXACTLY: absolute http/https + non-empty host else null; user/pass present → null; scheme+host strtolower; host bytes > 0x7F → null (punycode is the deterministic form); IPv6 literal hosts keep brackets, hex lowercased (wp_parse_url strips brackets — re-add when host contains ':'); drop :80(http)/:443(https), keep non-default ports; untrailingslashit(path), path case preserved, absent path = ''; drop query+fragment; return scheme://host[:port][path]. FAIL = null, never an exception"
  - "Normalization test table (NormalizeSiteTest): scheme/host case, both default ports, non-default port kept, path case preserved + trailing slash, query+fragment dropped, IPv6 lowercase-in-brackets, idempotence (normalize(normalize(x)) === normalize(x)); null cases: ftp scheme, scheme-relative, empty host, userinfo, non-URL garbage, IDN host with raw non-ASCII bytes"
  - "NEW class Woodev_License_Envelope_Verifier (class_exists-guarded, woodev/licensing/class-license-envelope-verifier.php): defines constant WOODEV_LICENSE_AUTHORITY_PUBKEY (define-if-missing, placeholder '') ; public static canonical_json( array $payload ): string = recursive ksort with SORT_STRING + wp_json_encode(payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); public static verify( array $envelope, ?string $public_key_b64 = null ): ?array — payload must be array, signature must be string, strict base64_decode(sig, true) length === SODIUM_CRYPTO_SIGN_BYTES (64), strict base64 key length === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES (32), optional envelope kid: absent or === first-16-hex of hash('sha256', raw pubkey) else null, function_exists('sodium_crypto_sign_verify_detached') guard (absent → null, never fatal), verify over canonical_json → payload array on success else null. NO site/plugin/time semantics in this class (callers own those)"
  - "Published test vector reproduced byte-for-byte (woodev-core spec, fixture seed = str_repeat(chr(1), 32) via sodium_crypto_sign_seed_keypair): assert pubkey b64 === 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w='; canonical_json of {site:'https://example.com', plugin_id:'216', license_required:false, issued_at:1749513600, expires_at:1750723200} === '{\"expires_at\":1750723200,\"issued_at\":1749513600,\"license_required\":false,\"plugin_id\":\"216\",\"site\":\"https://example.com\"}' (length 120); signature 'NPbp0Hce2UmggaOjNboRHhu4niepq/GdcBQDlHqIVl+3OJGuCy69sQdze4f97uhm4Hnny5EB3EcFfx1MbQb6DA==' verifies"
  - "Negative tests: tampered payload byte → null; wrong key → null; loose base64 (e.g. 'a===') → null; 63/65-byte sig → null; kid mismatch → null; matching kid → passes; empty/placeholder pubkey constant → null; sodium-absent path → null without Error, isolated with @runInSeparateProcess (gotcha testing/brain-monkey-function-pollution)"
  - "Wiring: both files require_once'd UNCONDITIONALLY in Woodev_Plugin::includes() immediately BEFORE the licensing requires block (~line 500) — gotcha framework/includes-wiring; dependency order: function file before verifier, verifier before class-plugin-license.php"
  - "Zero behavioral change to any existing class — purely additive; no existing test touched"
---

# Task

Implement plan task **s8-p1** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(specs: webhooks §2 + need-license §4.1/§4.2 — design locked, §9 resolutions recorded in the plan).

This is the ONE shared crypto primitive: §4 license-free claims (s8-p0) and license
commands (s8-p2+) both consume it. Keep it generic — signature + canonicalization + kid
only. Semantic checks (site binding, plugin id, expiry, nonce) belong to the consumers.

TDD: write `tests/unit/NormalizeSiteTest.php` and `tests/unit/LicenseEnvelopeVerifierTest.php`
FIRST, run to FAIL, then implement. Brain Monkey: stub `untrailingslashit`/`wp_parse_url`
with `Functions\when(...)->alias(...)` mapping to the real WP implementations (copy the
tiny wp_parse_url/untrailingslashit logic into the stub or alias to native parse_url with
the WP quirks — wp_parse_url on PHP 7.4+ is a thin parse_url wrapper); `wp_json_encode`
aliases to `json_encode`.

## What NOT to change
- Anything in `woodev/licensing/` existing classes (s8-p0 touches them, not you).
- `Woodev_REST_V1_Registrar`, REST controllers, updater.
- No production pubkey — constant placeholder `''` only.

## Gotchas to honor
- `framework/includes-wiring`; `testing/brain-monkey-function-pollution`
  (`@runInSeparateProcess` for the sodium-absent test); `bootstrap/multiversion-early-class-guards`
  (function_exists + class_exists guards).
- Conventions: WPCS tabs, Yoda, short arrays, full type declarations, docblocks with
  `@since 2.0.0`.

## Verification
- `./vendor/bin/phpunit tests/unit/NormalizeSiteTest.php tests/unit/LicenseEnvelopeVerifierTest.php`
  then full `composer check` green.
- Report: the exact canonical-bytes string your implementation produced for the test
  vector (must be the 120-byte string above, byte-for-byte).
